# Changelog

All notable changes to `beexar/sdk`. The version is shared across the Beexar SDKs
for Node, PHP, Go and Python — the same number always means the same contract
snapshot.

## 1.2.0 — 2026-10-23

Operator freespins, the SoftSwiss Freespins API one to one. Additions only: a
`WalletHandler` that does not issue freespins runs unchanged.

- `Client::issueFreespins()`, `Client::cancelFreespins()` and
  `Client::betLevels()` — Freespins/Issue, Freespins/Cancel and Bet/Levels,
  signed over the exact bytes like the launch calls.
- `/freespins/finish`, the fifth callback: implement the optional
  `Beexar\FreespinsFinishHandler` on your handler to receive a
  `Wallet\FreespinsFinishRequest` (`amount` as `Money`, zero allowed) and answer
  a `Wallet\FreespinsFinishResult`. Without it the route answers like an unknown
  one.
- `WalletError::invalidFreespinsIssue()` (api_code 610) and
  `WalletError::freespinsStateTransition()` (605); the `ApiCode::FREESPINS_*`
  constants.
- `ApiCode::FEATURE_NOT_SUPPORTED` (608): `issueFreespins()` answers it for a
  game that plays no freespins, and `ApiCode::GAME_NOT_AVAILABLE` (405) for a
  game your casino does not have; 601 is left for a missing bet level. 406
  reads "This feature is not enabled for your casino" (the SoftSwiss registry
  of 2026-10-25).
- `WalletServer::ROUTE_FREESPINS_FINISH`, also in `WalletServer::ROUTES`.
- `WalletServer::routeFromPath()` matches the longest route first.
  `/freespins/finish` ends with `/finish` too, and must not reach the round's
  finish handler.
- The reference wallet credits a freespins result once per issue id, and the
  conformance fixtures (version 2) cover it.
- Conformance fixtures version 3 add `0033-signature-non-ascii`: a malformed
  `X-REQUEST-SIGN` of the right length but not hex is refused like any bad
  signature (HTTP 400, api_code 403). `Signature::verify()` already did; the
  case and `testVerifyRefusesMalformedSignatures` pin it.
- The game catalog no longer lists the built-in slots game, removed on
  2026-10-24: `category` is `dice`, `plinko`, `crash` or `rgs`. `has_freespins`
  reports a game's own in-game free spins, not operator freespins; no game
  sets it today.

## 1.1.0 — 2026-10-04

PHP 8.3 or newer, up from 8.1: 8.1 is past end of life upstream and 8.2 leaves
security support on 2026-12-31. Composer on an older PHP keeps resolving 1.0.2.
No change to the API.

## 1.0.2 — 2026-09-18

No change to the code you consume. 1.0.1 reached PyPI, Packagist and the Go
proxy but not npm: the publish workflow pinned `npm@latest`, which had moved to
a major that refuses to run on the Node version the workflow used.

## 1.0.1 — 2026-09-18

No change to the code you consume. The release exists to move publishing onto
npm's and PyPI's trusted publishing, so no long-lived registry token is stored
anywhere any more.

## 1.0.0 — 2026-09-18

First public release.

- `Client` — launchReal, launchDemo, listGames, signed with HMAC-SHA256 over the exact request bytes.
- `WalletServer — the four seamless-wallet callbacks as one pure dispatch(route, rawBody, signature), plus handleGlobals() and a PSR-15 middleware`.
- Money — decimal amounts on digit-string arithmetic, so no bcmath or gmp is needed and no digit is lost.
- The full api_code registry, with the balance a required argument for codes
  100, 105 and 106.
- Types tracked against the published OpenAPI documents: a contract test fails
  if a property the spec declares never reaches your handler.
- Zero runtime dependencies.
