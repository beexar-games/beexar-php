# beexar/sdk

Beexar operator SDK for PHP. Launch game sessions, and serve the four
seamless-wallet callbacks the platform calls during play.

**Zero runtime dependencies** — no bcmath, no gmp, no HTTP client required.
PHP 8.3+.

```bash
composer require beexar/sdk
```

Full docs: **https://docs.beexar.com** · OpenAPI: **https://docs.beexar.com/api-reference/**

---

## The integration in one picture

There are two halves, and the second one is the work.

```
you  ──  POST /api/v1/softswiss/launcher/real  ──▶  Beexar     (Client)
                                                      │
player plays                                          │
                                                      ▼
your wallet  ◀──  POST /balance /betwin /rollback /finish  ──  Beexar   (WalletServer)
```

## Half 1 — launching a game

```php
use Beexar\Client;

$beexar = new Client(getenv('BEEXAR_CASINO_ID'), getenv('BEEXAR_API_SECRET'));

$launchUrl = $beexar->launchReal([
    'game' => 'dice',
    'account' => ['id' => 'player_123', 'currency' => 'EUR'],
    'locale' => 'en',
]);
// put $launchUrl in an iframe
```

`launchDemo()` does the same on a virtual balance and makes no wallet calls.
`listGames()` returns the catalogue enabled for you.

## Half 2 — serving the wallet

Implement four methods against your ledger. Everything else — signature
verification, parsing, validation, the error envelope — is handled.

```php
use Beexar\{Money, WalletError, WalletHandler, WalletServer};
use Beexar\Wallet\{BetWinRequest, BetWinResult, BetWinTransaction, BetWinTransactionResult, RequestContext};

final class Wallet implements WalletHandler
{
    public function betWin(BetWinRequest $request, RequestContext $context): BetWinResult
    {
        return $this->db->transaction(function () use ($request) {
            // Everything below must be in ONE database transaction. See "The boundary".
            $results = [];
            foreach ($request->transactions as $t) {
                if ($seen = $this->lookup($t->idProvider)) {
                    $results[] = new BetWinTransactionResult($t->idProvider, $seen['id']);
                    continue;
                }
                if ($this->isRolledBack($t->idProvider)) {
                    throw WalletError::alreadyRolledBack();
                }
                if ($t->type === BetWinTransaction::TYPE_BET && $this->balanceOf()->compare($t->amount) < 0) {
                    throw WalletError::insufficientFunds($this->balanceOf());
                }
                $results[] = new BetWinTransactionResult($t->idProvider, $this->apply($t));
            }
            return new BetWinResult($this->roundId($request->roundId), $this->balanceOf(), $results);
        });
    }

    // … balance(), rollback(), finish()
}

$server = new WalletServer(new Wallet(), getenv('BEEXAR_API_SECRET'));
$server->handleGlobals();   // no framework
```

Then point the four callback URLs in the backoffice at
`https://your-host/{balance,betwin,rollback,finish}` and run the
[Integration Test Game](https://docs.beexar.com/guides/testing/) — 29 scenarios
against your implementation.

A complete, correct wallet you can read in one sitting:
[`examples/InMemoryWallet.php`](./examples/InMemoryWallet.php). A runnable
server: [`examples/server.php`](./examples/server.php).

## The raw body — read this one

The signature is an HMAC over the **exact bytes** of the request. If anything
decodes the JSON and encodes it again before the SDK sees it, those bytes are
gone — key order, escaping and number rendering all change — and no signature
can ever match again.

| Your setup | What to do |
|---|---|
| No framework | `$server->handleGlobals()` — reads `php://input` once |
| Slim, Laravel, any PSR-15 | `new Beexar\Http\WalletMiddleware($server, $responseFactory)`, registered **before** any body-parsing middleware |
| Something else | read the body yourself and call `$server->dispatch($route, $rawBody, $signature)` |

`WalletMiddleware` needs `psr/http-server-middleware` and `psr/http-factory`;
the SDK itself pulls in neither.

When a signature fails and the SDK can tell why, the `$onWarning` callback you
pass to `WalletServer` receives the reason in plain words.

## Money

Amounts and balances are decimal strings in the currency's main unit — `"0.90"`
is ninety cents. `Money` cannot be built from a float, and its arithmetic runs
on digit strings, so it needs no extension and never loses a digit.

```php
(string) Money::parse('100.00')->sub(Money::parse('0.30'));  // "99.70"
(string) Money::fromMinorUnits('9970', 2);                   // "99.70" from an integer ledger
```

`"1E2000000000"` and anything past 16 decimals is rejected on shape, before any
arithmetic touches it.

## Errors

Two HTTP statuses exist on this contract, 400 and 500, and the meaning lives in
`meta.api_code`. Throw a `WalletError` and the envelope is built for you:

```php
throw WalletError::insufficientFunds($currentBalance); // 400 / api_code 100
throw WalletError::alreadyRolledBack();                // 400 / api_code 409
throw WalletError::invalidPlayer();                    // 400 / api_code 101
```

Codes 100, 105 and 106 **must** carry the player's balance, so those
constructors take it as a required argument — there is no way to build one
without it. Anything else you throw becomes an opaque 500 and your message never
leaves the process.

## The boundary

The SDK does **not** do idempotency or tombstones for you, and it will not
pretend to. Both have to happen in the same database transaction as the balance
update, and no library can join your transaction. What you must do:

1. Store every `id_provider` with a unique index, and check it **inside** the
   transaction that moves the money.
2. Store the response you returned — a repeat must return the id and balance you
   gave the first time, not today's.
3. On rollback, record a tombstone for `originalIdProvider` **whether or not**
   the original exists. Out-of-order delivery is normal; a later `/betwin` for a
   tombstoned id must be refused with `WalletError::alreadyRolledBack()`.

Your handler has **15 seconds**. The platform retries 5xx and timeouts for up to
30 seconds with the same `id_provider`; it does not retry insufficient funds,
bet limits, bad requests or signature failures.

## Freespins (optional)

Freespins follow the SoftSwiss Freespins API one to one: you give a player N
free rounds at a fixed bet, Beexar plays them without touching the wallet, and
reports what they won once, when the issue is over. Three calls go out from
you; one callback comes back.

```php
// The bet levels each game offers, per currency. A level is the total bet of one round.
$levels = $beexar->betLevels(games: ['dice'], currencies: ['EUR']);

// Record the issue on YOUR side first — the result will name only its issue_id.
$ledger->recordFreespinsIssue('fs-123', 'player_123', 'EUR');
$beexar->issueFreespins([
    'issue_id' => 'fs-123',
    'games' => ['dice'],
    'freespins_quantity' => 10,
    'bet_level' => 1,
    'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400), // UTC, at most one month ahead
    'account' => ['id' => 'player_123', 'currency' => 'EUR'],
]);
// The player launches the game as usual (launchReal) and finds them there.

$beexar->cancelFreespins(['issue_id' => 'fs-123']); // any time; a canceled issue is never reported
```

A repeated `issueFreespins()` with the same terms answers like the first, so
retry a timeout with the **same** array — a recomputed `valid_until` is other
terms, refused with api_code 610.

The result arrives on a fifth callback, `/freespins/finish`, carrying the issue
id and the amount won — zero included. Implement `Beexar\FreespinsFinishHandler`
on the same class as `WalletHandler`:

```php
use Beexar\Wallet\{FreespinsFinishRequest, FreespinsFinishResult, RequestContext};

final class Wallet implements WalletHandler, FreespinsFinishHandler
{
    public function freespinsFinish(FreespinsFinishRequest $request, RequestContext $context): FreespinsFinishResult
    {
        return $this->db->transaction(function () use ($request) {
            $issue = $this->freespinsIssue($request->issueId);
            if ($issue === null) {
                throw WalletError::invalidFreespinsIssue();             // api_code 610
            }
            if ($issue['finished']) {
                return new FreespinsFinishResult($issue['balance_after']); // a retry: credit nothing
            }
            if ($issue['canceled']) {
                throw WalletError::freespinsStateTransition();          // api_code 605: Beexar accepted your cancel
            }

            return new FreespinsFinishResult($this->credit($issue, $request->amount));
        });
    }
}
```

- It is sent **once per issue** — when the issue is played out, or expires with
  spins played — and retried with the same body (after 1, 3 and 5 minutes, then
  every 5) until you answer 200. Each attempt has the same **15 seconds** as the
  round callbacks. Credit once per issue id; a repeat returns the balance you
  stored the first time.
- Answer `605` only for an issue whose cancel Beexar accepted — Beexar never
  sends a Finish for one; a `605` to a Finish you did receive is retried like
  any refusal.
- Configure it in the backoffice as the operator's **freespins finish URL**, the
  fifth callback URL next to the four, e.g.
  `https://your-host/freespins/finish`. Leave it empty if you do not offer
  freespins — `issueFreespins()` then answers api_code 406.
- A handler that does not implement `FreespinsFinishHandler` keeps working
  unchanged; the route then answers like an unknown one (api_code 404).
- Paths are matched on their suffix, longest route first, so
  `/wallet/freespins/finish` and `/wallet/finish` live under one prefix.

The reference wallet implements it end to end:
[`examples/InMemoryWallet.php`](./examples/InMemoryWallet.php).

## Types come from the spec

The DTOs track the published OpenAPI documents (`openapi/` in this repo).
`tests/ContractTest.php` reads a normalised description of those specs and fails
if what the parser requires drifts from what the contract declares.

## License

MIT
