<?php

declare(strict_types=1);

namespace Beexar\Examples;

use Beexar\FreespinsFinishHandler;
use Beexar\Money;
use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BalanceResult;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinResult;
use Beexar\Wallet\BetWinTransaction;
use Beexar\Wallet\BetWinTransactionResult;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FinishResult;
use Beexar\Wallet\FreespinsFinishRequest;
use Beexar\Wallet\FreespinsFinishResult;
use Beexar\Wallet\RequestContext;
use Beexar\Wallet\RollbackRequest;
use Beexar\Wallet\RollbackResult;
use Beexar\Wallet\RollbackTransactionResult;
use Beexar\WalletError;
use Beexar\WalletHandler;

/**
 * A complete, correct wallet in memory.
 *
 * Not durable, not shared between processes, not your ledger. It exists to show
 * the three rules that decide whether an integration is correct, in the order
 * they have to happen:
 *
 *  1. Dedupe on id_provider FIRST. A repeat must return the transaction id and
 *     the balance you STORED the first time — not today's balance, not a fresh
 *     id. The platform retries, and a retry must be invisible.
 *  2. THEN check the tombstone. A rollback can arrive before the bet it
 *     reverses. When that bet finally shows up it must be refused with api_code
 *     409, not applied.
 *  3. THEN apply, atomically. Either every transaction in the request lands or
 *     none does, and an insufficient-funds answer reports the balance as it was
 *     BEFORE the batch — because nothing moved.
 *
 * Freespins follow the same discipline with issue_id as the key: record the
 * issue before you send Freespins/Issue, credit its /freespins/finish once, and
 * answer a repeat with the balance you stored the first time.
 *
 * In a real wallet all three must happen inside ONE database transaction, with
 * the id_provider under a unique index. That is why the SDK does not offer to
 * do idempotency for you: it cannot join your transaction, so it could only
 * pretend.
 */
final class InMemoryWallet implements WalletHandler, FreespinsFinishHandler
{
    public const FREESPINS_ISSUED = 'issued';
    public const FREESPINS_CANCELED = 'canceled';
    public const FREESPINS_FINISHED = 'finished';

    /** @var array<string, array{currency: string, balance: Money, bet_limited: bool}> */
    private array $accounts = [];

    /** @var array<string, array{id: string, balance_after: string, type?: string, amount?: string}> */
    private array $transactions = [];

    /** @var array<string, true> */
    private array $tombstones = [];

    /**
     * Whose freespins each issue is — /freespins/finish does not say — and,
     * once credited, the amount and the balance answered then.
     *
     * @var array<string, array{account_id: string, currency: string, status: string, amount?: string, balance_after?: string}>
     */
    private array $freespinsIssues = [];

    /** @var array<string, string> */
    private array $rounds = [];

    private int $nextTx = 1;
    private int $nextRound = 1;

    /**
     * @param array{
     *     accounts?: array<string, array{currency: string, balance: string, bet_limited?: bool}>,
     *     transactions?: array<string, array{id: string, balance_after: string, type?: string, amount?: string}>,
     *     tombstones?: list<string>,
     *     freespins_issues?: array<string, array{account_id: string, currency: string, status: string, amount?: string, balance_after?: string}>
     * } $state
     */
    public function __construct(array $state = [])
    {
        foreach ($state['accounts'] ?? [] as $id => $account) {
            $this->accounts[$id] = [
                'currency' => $account['currency'],
                'balance' => Money::parse($account['balance']),
                'bet_limited' => (bool) ($account['bet_limited'] ?? false),
            ];
        }
        $max = 0;
        foreach ($state['transactions'] ?? [] as $id => $transaction) {
            $this->transactions[$id] = $transaction;
            if (preg_match('/^op-tx-(\d+)$/', $transaction['id'], $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        foreach ($state['tombstones'] ?? [] as $id) {
            $this->tombstones[$id] = true;
        }
        foreach ($state['freespins_issues'] ?? [] as $id => $issue) {
            $this->freespinsIssues[$id] = $issue;
        }
        // Continue the id sequence rather than restarting it, so ids stay
        // unique across a restored state.
        $this->nextTx = $max + 1;
    }

    /** The current state, in the shape the constructor takes. */
    public function snapshot(): array
    {
        $accounts = [];
        foreach ($this->accounts as $id => $account) {
            $accounts[$id] = ['currency' => $account['currency'], 'balance' => (string) $account['balance']];
            if ($account['bet_limited']) {
                $accounts[$id]['bet_limited'] = true;
            }
        }

        return [
            'accounts' => $accounts,
            'transactions' => $this->transactions,
            'tombstones' => array_keys($this->tombstones),
            'freespins_issues' => $this->freespinsIssues,
        ];
    }

    public function balance(BalanceRequest $request, RequestContext $context): BalanceResult
    {
        return new BalanceResult($this->account($request->accountId)['balance']);
    }

    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult
    {
        $account = $this->account($request->accountId);
        $balanceBefore = $account['balance'];

        // Everything is computed against a working copy first. Nothing touches
        // the stored state until the whole batch is known to succeed.
        $running = $balanceBefore;
        $replayed = null;
        $applied = [];
        $results = [];

        foreach ($request->transactions as $transaction) {
            $seen = $this->transactions[$transaction->idProvider] ?? null;
            if ($seen !== null) {
                // 1. Replay: answer with what we stored, and change nothing.
                $results[] = new BetWinTransactionResult($transaction->idProvider, $seen['id']);
                $replayed = Money::parse($seen['balance_after']);

                continue;
            }
            if (isset($this->tombstones[$transaction->idProvider])) {
                // 2. Its rollback got here first.
                throw WalletError::alreadyRolledBack();
            }
            if ($transaction->type === BetWinTransaction::TYPE_BET && $account['bet_limited']) {
                throw WalletError::betLimitReached($balanceBefore);
            }

            // 3. Apply on the working copy.
            if ($transaction->type === BetWinTransaction::TYPE_BET) {
                $after = $running->sub($transaction->amount);
                if ($after->isNegative()) {
                    // Report the balance as it stands — nothing was applied.
                    throw WalletError::insufficientFunds($balanceBefore);
                }
                $running = $after;
            } else {
                $running = $running->add($transaction->amount);
            }

            $id = $this->allocateTxId();
            $applied[$transaction->idProvider] = [
                'id' => $id,
                'balance_after' => (string) $running,
                'type' => $transaction->type,
                'amount' => (string) $transaction->amount,
            ];
            $results[] = new BetWinTransactionResult($transaction->idProvider, $id);
        }

        // Commit only if something was actually applied. A request made
        // entirely of repeats must leave the ledger exactly as it found it.
        $balance = $running;
        if ($applied !== []) {
            foreach ($applied as $idProvider => $stored) {
                $this->transactions[$idProvider] = $stored;
            }
            $this->accounts[$request->accountId]['balance'] = $running;
        } elseif ($replayed !== null) {
            $balance = $replayed;
        }

        return new BetWinResult($this->roundIdFor($request->roundId), $balance, $results);
    }

    public function rollback(RollbackRequest $request, RequestContext $context): RollbackResult
    {
        $account = $this->account($request->accountId);
        $running = $account['balance'];
        $replayed = null;
        $changed = false;
        $results = [];

        foreach ($request->transactions as $transaction) {
            $seen = $this->transactions[$transaction->idProvider] ?? null;
            if ($seen !== null) {
                $results[] = new RollbackTransactionResult($transaction->idProvider, $seen['id']);
                $replayed = Money::parse($seen['balance_after']);

                continue;
            }

            // The tombstone goes down whether or not the original ever arrived.
            // That is what makes an out-of-order rollback safe: when the bet
            // turns up, the tombstone is already there to refuse it.
            $this->tombstones[$transaction->originalIdProvider] = true;
            $changed = true;

            $id = '';
            $original = $this->transactions[$transaction->originalIdProvider] ?? null;
            if ($original !== null && isset($original['type'], $original['amount'])) {
                $amount = Money::parse($original['amount']);
                $running = $original['type'] === BetWinTransaction::TYPE_BET
                    ? $running->add($amount)
                    // Reversing a win can only take back what is there.
                    // Clamping at zero keeps a player from going negative
                    // because of our bookkeeping.
                    : $running->sub($amount)->clampToZero();
                $id = $this->allocateTxId();
            }

            $this->transactions[$transaction->idProvider] = ['id' => $id, 'balance_after' => (string) $running];
            $results[] = new RollbackTransactionResult($transaction->idProvider, $id);
        }

        $balance = $running;
        if ($changed) {
            $this->accounts[$request->accountId]['balance'] = $running;
        } elseif ($replayed !== null) {
            $balance = $replayed;
        }

        return new RollbackResult($balance, $this->roundIdFor($request->roundIdProvider), $results);
    }

    public function finish(FinishRequest $request, RequestContext $context): FinishResult
    {
        // Nothing to settle: the round's money already moved through betWin.
        // Answering the current balance and staying idempotent is the whole job.
        return new FinishResult($this->account($request->accountId)['balance']);
    }

    /**
     * Records an issue BEFORE you send it with Client::issueFreespins().
     * /freespins/finish names only the issue_id, so this record is what says
     * whose wallet, in which currency, to credit — and a Finish can never
     * arrive for an issue you do not know.
     */
    public function recordFreespinsIssue(string $issueId, string $accountId, string $currency): void
    {
        $this->freespinsIssues[$issueId] ??= [
            'account_id' => $accountId,
            'currency' => $currency,
            'status' => self::FREESPINS_ISSUED,
        ];
    }

    /**
     * Marks an issue canceled once Beexar ACCEPTED your
     * Client::cancelFreespins(). A refused cancel (620: already played out)
     * leaves it issued, because its /freespins/finish is on its way.
     */
    public function cancelFreespinsIssue(string $issueId): void
    {
        if (($this->freespinsIssues[$issueId]['status'] ?? null) === self::FREESPINS_ISSUED) {
            $this->freespinsIssues[$issueId]['status'] = self::FREESPINS_CANCELED;
        }
    }

    public function freespinsFinish(FreespinsFinishRequest $request, RequestContext $context): FreespinsFinishResult
    {
        $issue = $this->freespinsIssues[$request->issueId] ?? null;
        if ($issue === null) {
            throw WalletError::invalidFreespinsIssue(sprintf('unknown freespins issue %s', $request->issueId));
        }

        // 1. Replay first, exactly as for id_provider: the platform retries
        //    until it gets a 200, and the retry must credit nothing and answer
        //    the balance stored the first time.
        if ($issue['status'] === self::FREESPINS_FINISHED) {
            if (!isset($issue['balance_after'])) {
                // Never credit a finished issue again; a 500 makes the platform retry.
                throw new \RuntimeException(sprintf('freespins issue %s is finished but has no stored balance', $request->issueId));
            }

            return new FreespinsFinishResult(Money::parse($issue['balance_after']));
        }
        // 2. A canceled issue has no result to take.
        if ($issue['status'] === self::FREESPINS_CANCELED) {
            throw WalletError::freespinsStateTransition(sprintf('freespins issue %s was canceled', $request->issueId));
        }

        // 3. Credit, and record the answer with the issue — one transaction in
        //    a real ledger, like everything else here. Zero is a valid result.
        $balance = $this->account($issue['account_id'])['balance']->add($request->amount);
        $this->accounts[$issue['account_id']]['balance'] = $balance;
        $this->freespinsIssues[$request->issueId] = [
            'account_id' => $issue['account_id'],
            'currency' => $issue['currency'],
            'status' => self::FREESPINS_FINISHED,
            'amount' => (string) $request->amount,
            'balance_after' => (string) $balance,
        ];

        return new FreespinsFinishResult($balance);
    }

    /** @return array{currency: string, balance: Money, bet_limited: bool} */
    private function account(string $accountId): array
    {
        if (!isset($this->accounts[$accountId])) {
            throw WalletError::invalidPlayer(sprintf('unknown account %s', $accountId));
        }

        return $this->accounts[$accountId];
    }

    private function allocateTxId(): string
    {
        return 'op-tx-' . $this->nextTx++;
    }

    private function roundIdFor(string $providerRoundId): string
    {
        return $this->rounds[$providerRoundId] ??= 'op-round-' . $this->nextRound++;
    }
}
