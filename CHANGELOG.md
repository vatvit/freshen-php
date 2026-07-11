# Changelog

All notable changes to `vatvit/freshen-php` (PHP) are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases are tagged `php-vX.Y.Z` in the monorepo.

## [Unreleased]

## [1.0.1] - 2026-07-11
### Changed
- README first screen reworked to lead with the value proposition — a tagline + benefit
  hook + a **Features** list (stale-while-revalidate, stampede prevention, async
  invalidation, structured keys, Redis/PSR-6, metrics/fail-open) — and the
  Composer/Packagist `description` is expanded to match (FRSH-035). The Security note moved
  from the top into a dedicated `## Security` section.

### Fixed
- Install section no longer shows the stale **pre-1.0 / release-candidate** notice or the
  `:^1.0@rc` requirement; `composer require vatvit/freshen-php` now resolves stable 1.0.0
  (FRSH-035).

## [1.0.0] - 2026-07-11
### Added
- README now opens with Packagist version/PHP/license badges and a **Security** note
  (Packagist advisory DB + `composer audit` + private GitHub Security Advisories
  reporting), plus a repo-level [SECURITY.md](../../SECURITY.md) policy (FRSH-029).

### Changed
- **Composer package renamed `vatvit/freshen` → `vatvit/freshen-php`** for naming
  symmetry with the bridges (`vatvit/freshen-symfony`, `vatvit/freshen-laravel`) and to
  match the mirror repo name. Update your `require` to `vatvit/freshen-php`. The old
  `vatvit/freshen` package covers only pre-1.0 release candidates (FRSH-032).
- README "Framework integration" now leads with the drop-in bridges
  (`vatvit/freshen-symfony`, `vatvit/freshen-laravel`) — `composer require` and you're
  done — with the hand-wiring kept as a condensed "Manual wiring" fallback (FRSH-025).

## [1.0.0-rc.3] - 2026-07-10
### Changed
- `invalidateExact([...], SyncMode::SYNC)` now issues a single `DEL` for the whole
  batch (one round-trip) instead of one `DEL` per key. Single-key calls are unchanged
  in effect; no public API change (FRSH-020).

### Fixed
- **`Cache::get()` on an uncached key no longer throws `Invalid TTL`.** The
  single-flight lock (`Stash\Item::lock()`) passes an *absolute* expiration, but
  `Freshen\Driver\Redis::storeAsLock()` treated it as a *relative* TTL and rejected
  anything over 300s — so the first `get()` of any cold key blew up against live
  Redis. `storeAsLock()` now derives the TTL from the absolute expiration and clamps
  the lock lifetime (FRSH-019).
- **`Cache::invalidate()` / `invalidateExact()` (SYNC — and therefore ASYNC) now
  actually delete.** They handed a `Key`/prefix *object* to the Stash driver's
  array-oriented `clear()`, which silently cleared the empty/root path and left the
  entry in place. Invalidation now routes through the pool `Item`, which carries the
  correct namespaced key path (FRSH-019).
- Added `Cache`→live-Redis integration coverage (`tests/Integration/CacheRedisTest`)
  exercising cold-key fill and both invalidation modes end-to-end — the seam the
  mock-based unit tests and driver-only integration tests never covered.

## [1.0.0-rc.2] - 2026-07-10
### Changed
- **BREAKING:** async invalidation/refresh now emit one event **per operation** instead of a
  single `AsyncEvent{key, exact}`. `Freshen\AsyncEvent` is now an abstract base; the concrete
  events are `Freshen\InvalidateEvent` (selector: `KeyPrefixInterface|KeyInterface`),
  `Freshen\InvalidateExactEvent` and `Freshen\RefreshEvent`. `AsyncHandler` gains
  `handleInvalidateExact()`; hosts wire each event class to its handler via a PSR-14 listener
  provider. This lets a single dispatcher route a `refresh` and an `invalidate` on the same key
  unambiguously — previously impossible, as both shared one event shape (FRSH-013).
- **BREAKING:** renamed the Stash enhancement classes for a cleaner public API —
  `Freshen\MyRedisDriver` → `Freshen\Driver\Redis`, `Freshen\MyItem` → `Freshen\Item`.
- `Cache` now wires `Freshen\Item` onto the pool itself (`setItemClass`), so deterministic
  TTLs and exact delete no longer require the host to configure the item class.

### Fixed
- `Freshen\Driver\Redis` now actually reuses an injected client
  (`new Freshen\Driver\Redis(['connection' => $redis])`); it previously fell through to
  `parent::setOptions()`, which overwrote the client with a fresh localhost connection.
- Deterministic TTL: `Freshen\Item` overrides Stash's `executeSet()` to drop Stash's random
  0–15% TTL reduction, so a given key stores an identical TTL every time
  ([Stash #419](https://github.com/tedious/Stash/issues/419)).
- ASYNC `invalidate()` / `invalidateExact()` / `refresh()` now dispatch **every** element of a
  list selector, not just the first.
- ASYNC hierarchical `invalidate()` by a `KeyPrefixInterface` no longer throws a `TypeError`:
  the dispatched `InvalidateEvent` accepts `KeyPrefixInterface|KeyInterface` (was baselined in
  FRSH-008, fixed here in FRSH-013).

## [1.0.0-rc.1] - 2026-07-09
### Added
- Initial project scaffold (namespace, autoloading, test/analyse tooling).
- Migrated the PoC cache implementation into this package: `Cache` (stale-while-revalidate
  with leader/follower single-flight), `Key`, async invalidation/refresh, jitter, Stash
  integration, and the public interfaces — namespace `Cache\` → `Freshen\`.

### Changed
- **PHP floor is 8.1.** Single source runs natively across 8.1 → 8.4 (no Rector downgrade /
  dist step). `tedivm/stash` requires ≥8.0; enums require 8.1.
- `ext-redis` is now a **suggestion**, not a hard requirement — the core works with any
  Stash pool; only `MyRedisDriver` needs the extension.
- `declare(strict_types=1)` added to every source file.
- Fail-open behaviour is now a constructor option (`bool $failOpen = true`).

### Fixed
- `invalidate()` / `invalidateExact()` / `refresh()` mishandled a single `Key` object: the
  `(array)` cast exploded the object's properties instead of wrapping it. Now wraps correctly.
- ASYNC `invalidate()` / `refresh()` without an `EventDispatcher` now throws a clear
  `LogicException` instead of a fatal call on `null`.
- `timestampsFromItem()` safely handles Stash's `DateTime|bool` return (no call on a bool).
