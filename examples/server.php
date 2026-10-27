<?php

/**
 * A complete Beexar wallet integration in one file, with no framework.
 *
 *   BEEXAR_API_SECRET=... php -S 0.0.0.0:8080 examples/server.php
 *
 * Point the four callback URLs in the backoffice at
 * http://<host>/{balance,betwin,rollback,finish} and run the Integration Test
 * Game against it. If you issue freespins, point the freespins finish URL at
 * http://<host>/freespins/finish as well.
 *
 * The ledger is kept in a JSON file, not in memory. PHP re-runs this script for
 * every request, so an in-memory wallet would forget each transaction the
 * moment it answered — and idempotency, which is the whole point, would be
 * untestable. The file is held under an exclusive lock for the duration of the
 * request, which is this example's stand-in for your database transaction.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Beexar\Examples\InMemoryWallet;
use Beexar\WalletServer;

$apiSecret = getenv('BEEXAR_API_SECRET');
if (!is_string($apiSecret) || $apiSecret === '') {
    http_response_code(500);
    echo "BEEXAR_API_SECRET is not set — refusing to start without a secret\n";

    exit(1);
}

$ledgerPath = getenv('BEEXAR_LEDGER_FILE') ?: sys_get_temp_dir() . '/beexar-example-ledger.json';

$lock = fopen($ledgerPath . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    http_response_code(500);
    echo '{"code":"internal","msg":"internal error","meta":{"api_code":"500","api_message":"internal error"}}';

    exit(1);
}

try {
    $state = ['accounts' => ['player_1' => ['currency' => 'EUR', 'balance' => '1000.00']]];
    if (is_file($ledgerPath)) {
        $stored = json_decode((string) file_get_contents($ledgerPath), true);
        if (is_array($stored)) {
            $state = $stored;
        }
    }

    $wallet = new InMemoryWallet($state);
    $server = new WalletServer(
        $wallet,
        $apiSecret,
        WalletServer::MAX_BODY_BYTES,
        static fn (string $message) => error_log('beexar: ' . $message),
    );

    $server->handleGlobals();

    // Persist AFTER answering, while still holding the lock. A real wallet
    // commits the transaction before it replies; this ordering is the closest
    // a file gets, and it is the one place this example is not a model to copy.
    file_put_contents($ledgerPath, json_encode($wallet->snapshot(), JSON_PRETTY_PRINT));
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
