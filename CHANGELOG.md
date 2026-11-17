# Changelog

## [1.1.0] - 2026-11-17

- `checkEvery($seconds)` and the `check_every` config: reuse a fingerprint for a
  few seconds instead of querying it on every read.

## [1.0.0] - 2026-11-17

- First release: `watch()`, `watchQuery()`, `scope()`, `key()`, `remember()`,
  `fingerprint()`, `withoutOverlapping()`, same-second grace window, Octane-safe
  stateless singleton and immutable builders.
