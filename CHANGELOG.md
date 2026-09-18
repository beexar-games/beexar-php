# Changelog

All notable changes to `beexar/sdk`. The version is shared across the Beexar SDKs
for Node, PHP, Go and Python — the same number always means the same contract
snapshot.

## 1.0.0 — unreleased

First public release.

- `Client` — launchReal, launchDemo, listGames, signed with HMAC-SHA256 over the exact request bytes.
- `WalletServer — the four seamless-wallet callbacks as one pure dispatch(route, rawBody, signature), plus handleGlobals() and a PSR-15 middleware`.
- Money — decimal amounts on digit-string arithmetic, so no bcmath or gmp is needed and no digit is lost.
- The full api_code registry, with the balance a required argument for codes
  100, 105 and 106.
- Types tracked against the published OpenAPI documents: a contract test fails
  if a property the spec declares never reaches your handler.
- Zero runtime dependencies.
