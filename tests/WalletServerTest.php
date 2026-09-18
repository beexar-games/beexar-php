<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\ApiCode;
use Beexar\Money;
use Beexar\Signature;
use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BalanceResult;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinResult;
use Beexar\Wallet\BetWinTransactionResult;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FinishResult;
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
