<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\ApiCode;
use Beexar\FreespinsFinishHandler;
use Beexar\Money;
use Beexar\Signature;
use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BalanceResult;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinResult;
use Beexar\Wallet\BetWinTransactionResult;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FinishResult;
use Beexar\Wallet\FreespinsFinishRequest;
use Beexar\Wallet\FreespinsFinishResult;
use Beexar\Wallet\RequestContext;
use Beexar\Wallet\RollbackRequest;
use Beexar\Wallet\RollbackResult;
use Beexar\WalletError;
use Beexar\WalletHandler;
use Beexar\WalletServer;
use PHPUnit\Framework\TestCase;

final class WalletServerTest extends TestCase
{
    private const SECRET = 'unit-test-secret';

    /**
     * A body whose key order no JSON serialiser produces. If anything parses
     * and re-serialises before the SDK sees it, these bytes change and the
     * signature cannot match.
     */
    private const HOSTILE_BODY = '{"transactions":[{"type":"bet","id_provider":"tx-1","amount":"10.00"}],'
        . '"round_id":"r-1","game_id":"dice","currency":"EUR","account_id":"player_1"}';

    public function testRefusesAnEmptyApiSecret(): void
    {
        // The gateway treats an empty secret as an automatic failure, so a
        // server built with one would authenticate nothing while looking healthy.
        $this->expectException(\InvalidArgumentException::class);
        new WalletServer(new StubWallet(), '   ');
    }

    public function testSignatureFailureIs400WithApiCode403(): void
    {
        $out = $this->server()->dispatch('/balance', '{}', str_repeat('0', 64));
        self::assertSame(400, $out['status'], 'the wallet contract never answers HTTP 403');
        self::assertSame(ApiCode::FORBIDDEN, json_decode($out['body'], true)['meta']['api_code']);
    }

    public function testVerifyRefusesMalformedSignatures(): void
    {
        // Parity with the Node and Python SDKs: a header that is not 64
        // lowercase hex characters is answered false, never with an error. The
        // non-ASCII rows are the shape that crashed the other two.
        foreach ([
            '64 non-ASCII chars' => str_repeat("\u{e9}", 64),
            '63 non-ASCII chars' => str_repeat("\u{e9}", 63),
            'empty' => '',
            'wrong length' => 'deadbeef',
            'wrong value' => str_repeat('0', 64),
        ] as $label => $signature) {
            self::assertFalse(Signature::verify('{}', $signature, self::SECRET), $label);
        }
        self::assertTrue(Signature::verify('{}', Signature::sign('{}', self::SECRET), self::SECRET));
    }

    public function testNonAsciiSignatureIs400WithApiCode403(): void
    {
        $out = $this->server()->dispatch('/balance', '{}', str_repeat("\u{e9}", 64));
        self::assertSame(400, $out['status'], 'a malformed header is a signature refusal, not a 500');
        self::assertSame(ApiCode::FORBIDDEN, json_decode($out['body'], true)['meta']['api_code']);
    }

    public function testDiagnosticNamesTheReserialisation(): void
    {
        $warnings = [];
        $server = $this->server(static function (string $m) use (&$warnings): void { $warnings[] = $m; });

        // What a body parser would hand over: json_encode of the parsed array.
        $reserialised = '{"account_id":"p","currency":"EUR","game_id":"dice"}';
        $server->dispatch('/balance', $reserialised, str_repeat('0', 64));
        self::assertStringContainsString('re-serialisation', implode(' ', $warnings));

        $warnings = [];
        $server->dispatch('/balance', '', str_repeat('0', 64));
        self::assertStringContainsString('already read php://input', implode(' ', $warnings));
    }

    public function testRefusesAnOversizeBody(): void
    {
        $huge = str_repeat('x', WalletServer::MAX_BODY_BYTES + 1);
        $out = $this->server()->dispatch('/balance', $huge, Signature::sign($huge, self::SECRET));
        self::assertSame(400, $out['status']);
        self::assertSame(ApiCode::BAD_REQUEST, json_decode($out['body'], true)['meta']['api_code']);
    }

    public function testValidationRunsBeforeTheHandler(): void
    {
        $cases = [
            ['/betwin', '{"account_id":"p","currency":"EUR","game_id":"d","round_id":"r","transactions":[{"id_provider":"t","type":"bet","amount":"0"}]}', 'greater than zero'],
            ['/balance', '{"account_id":"p","currency":"eur","game_id":"d"}', 'currency'],
            ['/balance', '{', 'JSON'],
        ];
        foreach ($cases as [$route, $body, $needle]) {
            $out = $this->server()->dispatch($route, $body, Signature::sign($body, self::SECRET));
            self::assertSame(400, $out['status'], $body);
            self::assertStringContainsString($needle, $out['body']);
        }
    }

    public function testUnknownFieldsAreTolerated(): void
    {
        // additionalProperties is true on purpose, and the gateway probes
        // operators with an extra field. Strictness here breaks a valid request.
        $body = '{"account_id":"p","currency":"EUR","game_id":"d","new_param":"probe"}';
        $out = $this->server()->dispatch('/balance', $body, Signature::sign($body, self::SECRET));
        self::assertSame(200, $out['status'], $out['body']);
    }

    public function testUnexpectedThrowLeaksNothing(): void
    {
        $server = new WalletServer(new StubWallet(new \RuntimeException('connection to ledger-db-7 refused')), self::SECRET);
        $out = $server->dispatch('/betwin', self::HOSTILE_BODY, Signature::sign(self::HOSTILE_BODY, self::SECRET));
        self::assertSame(500, $out['status']);
        self::assertStringNotContainsString('ledger-db-7', $out['body']);
    }

    public function testFundsErrorsCannotBeBuiltWithoutABalance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WalletError::withApiCode(ApiCode::INSUFFICIENT_FUNDS, 'no funds');
    }

    public function testBonusAmountDefaultsToZero(): void
    {
        // The contract requires the field in every transaction.
        $out = $this->server()->dispatch('/betwin', self::HOSTILE_BODY, Signature::sign(self::HOSTILE_BODY, self::SECRET));
        self::assertSame('0.00', json_decode($out['body'], true)['transactions'][0]['bonus_amount']);
    }

    public function testRouteFromPathAcceptsAnyMountPrefix(): void
    {
        // The gateway POSTs to the full callback URL from the backoffice.
        foreach (['/betwin', '/wallet/betwin', '/api/v2/psp/betwin', '/wallet/betwin/'] as $path) {
            self::assertSame('/betwin', WalletServer::routeFromPath($path), $path);
        }
        self::assertNull(WalletServer::routeFromPath('/health'));
    }

    public function testFreespinsFinishReachesTheOptionalHandler(): void
    {
        $wallet = new FreespinsStubWallet();
        $server = new WalletServer($wallet, self::SECRET);

        $body = '{"issue_id":"fs-1","amount":"12.50"}';
        $out = $server->dispatch('/freespins/finish', $body, Signature::sign($body, self::SECRET));
        self::assertSame(200, $out['status'], $out['body']);
        self::assertSame(['balance' => '112.50'], json_decode($out['body'], true));
        self::assertSame('fs-1', $wallet->seen?->issueId);
        self::assertSame('12.50', (string) $wallet->seen?->amount);

        // Zero is a result here, not a bad request: the freespins won nothing.
        $zero = '{"issue_id":"fs-1","amount":"0"}';
        $out = $server->dispatch('/freespins/finish', $zero, Signature::sign($zero, self::SECRET));
        self::assertSame(200, $out['status'], $out['body']);
        self::assertTrue($wallet->seen?->amount->isZero());
    }

    public function testFreespinsFinishIsValidatedBeforeTheHandler(): void
    {
        $cases = [
            ['{"issue_id":"fs-1","amount":"-1.00"}', 'amount: not a valid decimal amount'],
            ['{"issue_id":"fs-1","amount":12.5}', 'amount: not a valid decimal amount'],
            ['{"issue_id":"fs-1"}', 'amount: not a valid decimal amount'],
            ['{"amount":"12.50"}', 'issue_id: required, must be a non-empty string'],
        ];
        foreach ($cases as [$body, $message]) {
            $wallet = new FreespinsStubWallet();
            $out = (new WalletServer($wallet, self::SECRET))
                ->dispatch('/freespins/finish', $body, Signature::sign($body, self::SECRET));
            self::assertSame(400, $out['status'], $body);
            self::assertSame($message, json_decode($out['body'], true)['msg'], $body);
            self::assertNull($wallet->seen, 'the handler ran on ' . $body);
        }
    }

    public function testFreespinsFinishWithoutTheHandlerAnswersLikeAnUnknownRoute(): void
    {
        // StubWallet has the four methods only — a wallet written before
        // freespins existed, or one that does not offer them. It must keep
        // working, and answer /freespins/finish the way an unknown route is.
        $body = '{"issue_id":"fs-1","amount":"12.50"}';
        $out = $this->server()->dispatch('/freespins/finish', $body, Signature::sign($body, self::SECRET));

        self::assertSame(400, $out['status']);
        self::assertSame([
            'code' => 'invalid_argument',
            'msg' => 'freespins finish is not implemented by this wallet',
            'meta' => ['api_code' => ApiCode::NOT_FOUND, 'api_message' => 'freespins finish is not implemented by this wallet'],
        ], json_decode($out['body'], true));

        $balance = '{"account_id":"p","currency":"EUR","game_id":"d"}';
        self::assertSame(200, $this->server()->dispatch('/balance', $balance, Signature::sign($balance, self::SECRET))['status']);
    }

    public function testFreespinsRefusalsCarryTheirApiCodes(): void
    {
        self::assertSame('610', WalletError::invalidFreespinsIssue()->toEnvelope()['meta']['api_code']);
        self::assertSame('605', WalletError::freespinsStateTransition()->toEnvelope()['meta']['api_code']);
    }

    public function testRouteFromPathPrefersTheLongestRoute(): void
    {
        // /freespins/finish ends with /finish too. Matching the shorter route
        // first would hand a freespins result to the round's finish handler.
        foreach ([
            '/freespins/finish' => '/freespins/finish',
            '/x/freespins/finish' => '/freespins/finish',
            '/wallet/freespins/finish/' => '/freespins/finish',
            '/finish' => '/finish',
            '/x/finish' => '/finish',
        ] as $path => $route) {
            self::assertSame($route, WalletServer::routeFromPath($path), $path);
        }
        self::assertNull(WalletServer::routeFromPath('/freespins'));
    }

    private function server(?callable $onWarning = null): WalletServer
    {
        return new WalletServer(new StubWallet(), self::SECRET, WalletServer::MAX_BODY_BYTES, $onWarning);
    }
}

final class StubWallet implements WalletHandler
{
    public function __construct(private readonly ?\Throwable $betWinError = null)
    {
    }

    public function balance(BalanceRequest $request, RequestContext $context): BalanceResult
    {
        return new BalanceResult(Money::parse('100.00'));
    }

    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult
    {
        if ($this->betWinError !== null) {
            throw $this->betWinError;
        }
        $results = [];
        foreach ($request->transactions as $t) {
            $results[] = new BetWinTransactionResult($t->idProvider, 'own-1');
        }

        return new BetWinResult('own-round', Money::parse('90.00'), $results);
    }

    public function rollback(RollbackRequest $request, RequestContext $context): RollbackResult
    {
        return new RollbackResult(Money::parse('100.00'), 'own-round', []);
    }

    public function finish(FinishRequest $request, RequestContext $context): FinishResult
    {
        return new FinishResult(Money::parse('100.00'));
    }
}

final class FreespinsStubWallet implements WalletHandler, FreespinsFinishHandler
{
    public ?FreespinsFinishRequest $seen = null;

    public function balance(BalanceRequest $request, RequestContext $context): BalanceResult
    {
        return new BalanceResult(Money::parse('100.00'));
    }

    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult
    {
        return new BetWinResult('own-round', Money::parse('90.00'), []);
    }

    public function rollback(RollbackRequest $request, RequestContext $context): RollbackResult
    {
        return new RollbackResult(Money::parse('100.00'), 'own-round', []);
    }

    public function finish(FinishRequest $request, RequestContext $context): FinishResult
    {
        return new FinishResult(Money::parse('100.00'));
    }

    public function freespinsFinish(FreespinsFinishRequest $request, RequestContext $context): FreespinsFinishResult
    {
        $this->seen = $request;

        return new FreespinsFinishResult(Money::parse('112.50'));
    }
}
