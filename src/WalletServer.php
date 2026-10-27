<?php

declare(strict_types=1);

namespace Beexar;

use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinTransaction;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FreespinsFinishRequest;
use Beexar\Wallet\RequestContext;
use Beexar\Wallet\RollbackRequest;
use Beexar\Wallet\RollbackTransaction;

/**
 * Turns the callbacks into one pure function.
 *
 * dispatch() takes bytes and a signature and returns a status and a body. No
 * framework type crosses that boundary and it never sees a parsed body from the
 * outside, so signing the wrong thing is not expressible.
 */
final class WalletServer
{
    /**
     * The gateway caps an operator request body at 64 KiB. The wallet server
     * mirrors that so a body it would never have sent cannot make your process
     * allocate.
     */
    public const MAX_BODY_BYTES = 64 * 1024;

    public const ROUTE_BALANCE = '/balance';
    public const ROUTE_BETWIN = '/betwin';
    public const ROUTE_ROLLBACK = '/rollback';
    public const ROUTE_FINISH = '/finish';

    /**
     * Served only by a handler that also implements FreespinsFinishHandler —
     * a wallet that issues freespins.
     */
    public const ROUTE_FREESPINS_FINISH = '/freespins/finish';

    public const ROUTES = [
        self::ROUTE_BALANCE,
        self::ROUTE_BETWIN,
        self::ROUTE_ROLLBACK,
        self::ROUTE_FINISH,
        self::ROUTE_FREESPINS_FINISH,
    ];

    private const FREESPINS_NOT_IMPLEMENTED = 'freespins finish is not implemented by this wallet';

    /** @var callable(string): void */
    private $warn;

    /**
     * @param string   $apiSecret the operator API secret from the backoffice
     * @param null|callable(string): void $onWarning receives a diagnostic when the SDK can tell WHY a signature failed
     */
    public function __construct(
        private readonly WalletHandler $handler,
        private readonly string $apiSecret,
        private readonly int $maxBodyBytes = self::MAX_BODY_BYTES,
        ?callable $onWarning = null,
    ) {
        if (trim($apiSecret) === '') {
            // The gateway treats an empty secret as an automatic verification
            // failure, so a server built with one would authenticate nothing
            // while looking healthy.
            throw new \InvalidArgumentException('WalletServer: apiSecret is required and must be non-empty');
        }
        $this->warn = $onWarning ?? static function (string $message): void {
        };
    }

    /**
     * Maps a request path to one of the callback routes.
     *
     * Matching is on the SUFFIX because the mount path belongs to you: the
     * gateway POSTs to the full callback URL configured in the backoffice,
     * prefix and all, so /api/v2/psp/betwin is as valid as /betwin. The longest
     * route wins, so /wallet/freespins/finish is the freespins result and never
     * the round's /finish.
     */
    public static function routeFromPath(string $path): ?string
    {
        $clean = rtrim(explode('?', $path, 2)[0], '/');
        if ($clean === '') {
            $clean = '/';
        }

        // /freespins/finish ends with /finish too, so a suffix match has to try
        // it before the shorter route it contains.
        $routes = self::ROUTES;
        usort($routes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($routes as $route) {
            if ($clean === $route || str_ends_with($clean, $route)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Verifies, parses, validates and routes one callback.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function dispatch(string $route, string $rawBody, ?string $signature, array $headers = []): array
    {
        try {
            if (!\in_array($route, self::ROUTES, true)) {
                throw WalletError::notFound(sprintf('unknown route: %s', $route));
            }
            if ($route === self::ROUTE_FREESPINS_FINISH && !$this->handler instanceof FreespinsFinishHandler) {
                // An operator that does not issue freespins has nothing to
                // settle; the route answers the way an unknown one does.
                throw WalletError::notFound(self::FREESPINS_NOT_IMPLEMENTED);
            }
            if (\strlen($rawBody) > $this->maxBodyBytes) {
                throw WalletError::badRequest('request body too large');
            }
            if (!Signature::verify($rawBody, $signature, $this->apiSecret)) {
                $this->explainSignatureFailure($rawBody, $signature);
                throw WalletError::invalidSignature();
            }

            /** @var mixed $parsed */
            $parsed = json_decode($rawBody, true);
            if (!\is_array($parsed) || array_is_list($parsed)) {
                throw WalletError::badRequest('body must be a JSON object');
            }

            $context = new RequestContext($route, $rawBody, $headers);

            return self::ok($this->invoke($route, $parsed, $context));
        } catch (WalletError $error) {
            return [
                'status' => $error->status,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => self::encode($error->toEnvelope()),
            ];
        } catch (\InvalidArgumentException $error) {
            // A programming error in the calling code — a funds error built
            // without a balance. Surface it; swallowing it would hide a bug
            // that only ever produces wrong money.
            throw $error;
        } catch (\Throwable) {
            // Anything the handler threw: answer 500 and say nothing about it.
            // The platform retries 5xx with the same id_provider, so this is
            // the retryable shape, and the message never leaves the process.
            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => self::encode(WalletError::internal()->toEnvelope()),
            ];
        }
    }

    /**
     * Serves the request from PHP's superglobals and writes the response —
     * everything you need when there is no framework in the way.
     */
    public function handleGlobals(): void
    {
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $route = self::routeFromPath($path);
        if ($route === null || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(404);
            header('Content-Type: application/json');
            echo self::encode(WalletError::notFound()->toEnvelope());

            return;
        }

        // Read the body ONCE, as bytes. php://input is not always re-readable.
        $rawBody = (string) file_get_contents('php://input', false, null, 0, $this->maxBodyBytes + 1);
        $signature = $_SERVER['HTTP_X_REQUEST_SIGN'] ?? null;

        $out = $this->dispatch($route, $rawBody, \is_string($signature) ? $signature : null, self::headersFromServer());
        http_response_code($out['status']);
        foreach ($out['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $out['body'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function invoke(string $route, array $body, RequestContext $context): array
    {
        switch ($route) {
            case self::ROUTE_BALANCE:
                $result = $this->handler->balance(self::parseBalance($body), $context);

                return ['balance' => (string) $result->balance];

            case self::ROUTE_BETWIN:
                $result = $this->handler->betWin(self::parseBetWin($body), $context);
                $transactions = [];
                foreach ($result->transactions as $t) {
                    $transactions[] = [
                        'id_provider' => $t->idProvider,
                        'id' => $t->id,
                        'bonus_amount' => ($t->bonusAmount ?? Money::zero())->formatBonus(),
                    ];
                }

                return [
                    'round_id' => $result->roundId,
                    'transactions' => $transactions,
                    'balance' => (string) $result->balance,
                ];

            case self::ROUTE_ROLLBACK:
                $result = $this->handler->rollback(self::parseRollback($body), $context);
                $transactions = [];
                foreach ($result->transactions as $t) {
                    $transactions[] = ['id_provider' => $t->idProvider, 'id' => $t->id];
                }

                return [
                    'balance' => (string) $result->balance,
                    'round_id' => $result->roundId,
                    'transactions' => $transactions,
                ];

            case self::ROUTE_FINISH:
                $result = $this->handler->finish(self::parseFinish($body), $context);

                return ['balance' => (string) $result->balance];

            case self::ROUTE_FREESPINS_FINISH:
                $request = self::parseFreespinsFinish($body);
                if (!$this->handler instanceof FreespinsFinishHandler) {
                    throw WalletError::notFound(self::FREESPINS_NOT_IMPLEMENTED);
                }
                $result = $this->handler->freespinsFinish($request, $context);

                return ['balance' => (string) $result->balance];

            default:
                throw WalletError::notFound(sprintf('unknown route: %s', $route));
        }
    }

    /**
     * Says why a signature failed when the SDK can tell.
     *
     * Nearly every failed integration is the same bug: a body-parsing layer
     * consumed the stream, and what reaches the SDK is a re-serialisation
     * rather than the bytes that were signed. That is unrecoverable — the
     * original bytes are gone — but it is recognisable, and naming it saves days.
     */
    private function explainSignatureFailure(string $rawBody, ?string $signature): void
    {
        $warn = $this->warn;

        if ($signature === null || $signature === '') {
            $warn('X-REQUEST-SIGN header is missing from the request');

            return;
        }
        if ($rawBody === '') {
            $warn(
                'the request body reaching the SDK is empty, which usually means something '
                . 'already read php://input. Read it once and pass the same string to dispatch().',
            );

            return;
        }

        $parsed = json_decode($rawBody, true);
        if ($parsed !== null && json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) === $rawBody) {
            $warn(
                'the body reaching the SDK is byte-identical to a JSON re-serialisation. If your '
                . 'framework parsed the body before the SDK saw it, the bytes that were signed are '
                . 'already lost and the signature can never match.',
            );

            return;
        }

        $warn('signature mismatch: check that the API secret matches the one in the backoffice');
    }

    // --- parsing and validation, all of it before the handler runs ---------

    /** @param array<string, mixed> $body */
    private static function parseBalance(array $body): BalanceRequest
    {
        return new BalanceRequest(
            self::requireString($body, 'account_id'),
            self::requireCurrency($body),
            self::requireString($body, 'game_id'),
            self::optionalString($body, 'session_id'),
        );
    }

    /** @param array<string, mixed> $body */
    private static function parseBetWin(array $body): BetWinRequest
    {
        $transactions = [];
        foreach (self::requireArray($body, 'transactions') as $i => $raw) {
            if (!\is_array($raw)) {
                throw WalletError::badRequest(sprintf('transactions[%d]: must be an object', $i));
            }
            $type = self::requireString($raw, 'type', sprintf('transactions[%d].type', $i));
            if ($type !== BetWinTransaction::TYPE_BET && $type !== BetWinTransaction::TYPE_WIN) {
                throw WalletError::badRequest(sprintf('transactions[%d].type: must be "bet" or "win"', $i));
            }
            $transactions[] = new BetWinTransaction(
                self::requireString($raw, 'id_provider', sprintf('transactions[%d].id_provider', $i)),
                $type,
                self::requireAmount($raw['amount'] ?? null, sprintf('transactions[%d].amount', $i)),
            );
        }

        return new BetWinRequest(
            self::requireString($body, 'account_id'),
            self::requireCurrency($body),
            self::requireString($body, 'game_id'),
            self::requireString($body, 'round_id'),
            (bool) ($body['finished'] ?? false),
            $transactions,
            self::optionalString($body, 'session_id'),
        );
    }

    /** @param array<string, mixed> $body */
    private static function parseRollback(array $body): RollbackRequest
    {
        $transactions = [];
        foreach (self::requireArray($body, 'transactions') as $i => $raw) {
            if (!\is_array($raw)) {
                throw WalletError::badRequest(sprintf('transactions[%d]: must be an object', $i));
            }
            $type = self::requireString($raw, 'type', sprintf('transactions[%d].type', $i));
            if ($type !== 'rollback') {
                throw WalletError::badRequest(sprintf('transactions[%d].type: must be "rollback"', $i));
            }
            $transactions[] = new RollbackTransaction(
                self::requireString($raw, 'id_provider', sprintf('transactions[%d].id_provider', $i)),
                self::requireString($raw, 'original_id_provider', sprintf('transactions[%d].original_id_provider', $i)),
            );
        }

        return new RollbackRequest(
            self::requireString($body, 'account_id'),
            self::requireCurrency($body),
            self::optionalString($body, 'game_id') ?? '',
            self::requireString($body, 'round_id_provider'),
            (bool) ($body['finished'] ?? false),
            $transactions,
            self::optionalString($body, 'session_id'),
        );
    }

    /** @param array<string, mixed> $body */
    private static function parseFinish(array $body): FinishRequest
    {
        return new FinishRequest(
            self::requireString($body, 'account_id'),
            self::requireCurrency($body),
            self::requireString($body, 'round_id'),
            self::optionalString($body, 'session_id'),
        );
    }

    /** @param array<string, mixed> $body */
    private static function parseFreespinsFinish(array $body): FreespinsFinishRequest
    {
        $issueId = self::requireString($body, 'issue_id');

        // Zero is a valid result here — the freespins won nothing — so unlike a
        // /betwin amount it is not refused. The shape rules are the same.
        $amount = Money::tryParse($body['amount'] ?? null);
        if ($amount === null) {
            throw WalletError::badRequest('amount: not a valid decimal amount');
        }

        return new FreespinsFinishRequest($issueId, $amount);
    }

    /** @param array<string, mixed> $body */
    private static function requireString(array $body, string $field, ?string $label = null): string
    {
        $value = $body[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            throw WalletError::badRequest(($label ?? $field) . ': required, must be a non-empty string');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $body */
    private static function requireCurrency(array $body): string
    {
        $value = $body['currency'] ?? null;
        if (!\is_string($value) || preg_match(Currency::PATTERN, $value) !== 1) {
            throw WalletError::badRequest('currency: must match ' . Currency::PATTERN_SOURCE);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<int, mixed>
     */
    private static function requireArray(array $body, string $field): array
    {
        $value = $body[$field] ?? null;
        if (!\is_array($value) || $value === []) {
            throw WalletError::badRequest($field . ': required, must be a non-empty array');
        }

        return array_values($value);
    }

    private static function requireAmount(mixed $raw, string $path): Money
    {
        $amount = Money::tryParse($raw);
        if ($amount === null) {
            throw WalletError::badRequest($path . ': not a valid decimal amount');
        }
        if (!$amount->isPositive()) {
            throw WalletError::badRequest($path . ': must be greater than zero');
        }

        return $amount;
    }

    /** @return array<string, string> */
    private static function headersFromServer(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'HTTP_') && \is_string($value)) {
                $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private static function ok(array $body): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => self::encode($body)];
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
