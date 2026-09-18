<?php

declare(strict_types=1);

namespace Beexar\Tests;

use Beexar\Examples\InMemoryWallet;
use Beexar\Signature;
use Beexar\Wallet\BalanceRequest;
use Beexar\Wallet\BalanceResult;
use Beexar\Wallet\BetWinRequest;
use Beexar\Wallet\BetWinResult;
use Beexar\Wallet\FinishRequest;
use Beexar\Wallet\FinishResult;
use Beexar\Wallet\RequestContext;
use Beexar\Wallet\RollbackRequest;
use Beexar\Wallet\RollbackResult;
use Beexar\WalletHandler;
use Beexar\WalletServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cross-language conformance suite. The same fixtures are run by the Node,
 * Go and Python SDKs, so behaviour cannot drift between languages while there
 * is only one set of cases.
 */
final class ConformanceTest extends TestCase
{
    

    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        $manifest = self::readJson('manifest.json');
        $out = [];
        foreach ($manifest['cases'] as $case) {
            $out[$case['dir']] = [$case['dir'], $case['endpoint']];
        }
        self::assertNotEmpty($out);

        return $out;
    }

    #[DataProvider('cases')]
    public function testCase(string $dir, string $endpoint): void
    {
        $body = self::read("cases/{$dir}/request.body");
        $request = self::readJson("cases/{$dir}/request.json");
        $before = self::readJson("cases/{$dir}/ledger.before.json");
        $after = self::readJson("cases/{$dir}/ledger.after.json");
        $expected = self::readJson("cases/{$dir}/response.json");
        $secret = self::read('secret.txt');

        // A fixture whose stored signature does not match its own bytes would
        // quietly test nothing, so the suite re-derives it.
        $signature = $request['headers']['X-REQUEST-SIGN'] ?? null;
        if ($signature !== null && $signature !== str_repeat('0', 64)) {
            self::assertSame(Signature::sign($body, $secret), $signature, 'fixture signature does not match its own body');
        }

        $wallet = new InMemoryWallet($before);
        $server = new WalletServer(new ExplodingWallet($wallet), $secret);

        $out = $server->dispatch($endpoint, $body, $signature);

        self::assertSame($expected['status'], $out['status'], 'body: ' . $out['body']);
        self::assertEquals($expected['body'], json_decode($out['body'], true));
        self::assertEquals(self::normalise($after), self::normalise($wallet->snapshot()));
    }

    /**
     * @param array<string, mixed> $ledger
     *
     * @return array<string, mixed>
     */
    private static function normalise(array $ledger): array
    {
        $tombstones = $ledger['tombstones'] ?? [];
        sort($tombstones);
        ksort($ledger['accounts']);
        ksort($ledger['transactions']);

        return [
            'accounts' => $ledger['accounts'] ?? [],
            'transactions' => $ledger['transactions'] ?? [],
            'tombstones' => $tombstones,
        ];
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(Fixtures::root() . "/" . $relative);
        self::assertIsString($contents, "missing fixture {$relative}");

        return $contents;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $relative): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::read($relative), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

/**
 * Makes the "boom" account throw something that is not a WalletError, so the
 * "handler raised the unexpected" path is exercised without putting a test hook
 * in the reference wallet itself.
 */
final class ExplodingWallet implements WalletHandler
{
    public function __construct(private readonly InMemoryWallet $wallet)
    {
    }

    public function balance(BalanceRequest $request, RequestContext $context): BalanceResult
    {
        if ($request->accountId === 'boom') {
            throw new \RuntimeException('simulated ledger outage');
        }

        return $this->wallet->balance($request, $context);
    }

    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult
    {
        return $this->wallet->betWin($request, $context);
    }

    public function rollback(RollbackRequest $request, RequestContext $context): RollbackResult
    {
        return $this->wallet->rollback($request, $context);
    }

    public function finish(FinishRequest $request, RequestContext $context): FinishResult
    {
        return $this->wallet->finish($request, $context);
    }
}
