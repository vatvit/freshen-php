<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\CacheInterface;
use Freshen\Interface\PsrPoolAccessInterface;
use Freshen\Interface\KeyInterface;
use Freshen\Interface\LoaderInterface;
use Freshen\Interface\JitterInterface;
use Freshen\Interface\KeyPrefixInterface;
use Freshen\Interface\MetricsInterface;
use Freshen\Interface\ValueResultInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stash\Interfaces\ItemInterface;
use Stash\Interfaces\PoolInterface as StashPoolInterface;
use Stash\Invalidation;

class Cache implements CacheInterface, PsrPoolAccessInterface
{
    public function __construct(
        private StashPoolInterface            $pool,
        private LoaderInterface               $loader,
        private int                           $hardTtlSec,
        private int                           $precomputeSec,         // seconds BEFORE hard TTL to precompute (soft window)
        private JitterInterface               $jitter,
        private EventDispatcherInterface|null $eventDispatcher = null,
        private MetricsInterface|null         $metrics = null,
        private bool                          $failOpen = true,
    )
    {
        if ($hardTtlSec < 1) throw new \InvalidArgumentException('hardTtlSec must be >= 1');
        if ($precomputeSec < 0 || $precomputeSec > $hardTtlSec) {
            throw new \InvalidArgumentException('precomputeSec must be in [0, hardTtlSec]');
        }

        // Guarantee the deterministic-TTL + exact-clear behaviour regardless of how the
        // pool was built: Freshen\Item is required, so wire it here rather than trust the host.
        $this->pool->setItemClass(Item::class);
    }

    public function get(KeyInterface $key): ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());

        // 1) fast path: fresh hit
        if ($result = $this->tryFreshHit($item)) {
            return $result;
        }

        // 2) single-flight: become the leader and recompute
        $won = $item->lock();
        if ($won) {
            try {
                return $this->leaderComputeAndSave($key);
            } finally {
                // lock is released when $item is out of scope (Stash frees it with Item lifecycle)
                unset($item);
            }
        }

        // 3) follower path: serve stale
        if ($result = $this->tryFollowerServeStale($key)) {
            return $result;
        }

        // 4) follower path: wait for leader to finish
        if ($result = $this->tryFollowerWaitFresh($key)) {
            return $result;
        }

        // 5) last resort: fail-open compute or miss
        return $this->failOpenOrMiss($key);
    }

    /**
     * Extract createdAt and soft boundary (unix seconds) from a Stash item.
     *
     * @return array{int, int} [createdAt, softExpiresAt]
     */
    private function timestampsFromItem(ItemInterface $item): array
    {
        $creation = $item->getCreation();
        $expiration = $item->getExpiration();
        $createdAt = $creation instanceof \DateTimeInterface ? $creation->getTimestamp() : time();
        $expiresAt = $expiration instanceof \DateTimeInterface
            ? $expiration->getTimestamp()
            : ($createdAt + $this->hardTtlSec);
        $softAt = $expiresAt - $this->precomputeSec;
        if ($softAt < $createdAt) {
            $softAt = $createdAt;
        }
        return [$createdAt, $softAt];
    }

    /** Try to return a fresh hit if available. Returns null on miss. */
    private function tryFreshHit(ItemInterface $item): ?ValueResultInterface
    {
        $item->setInvalidationMethod(Invalidation::PRECOMPUTE, $this->precomputeSec);
        $value = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh']);
            return ValueResult::hit($value, $createdAt, $softAt);
        }
        return null;
    }

    /** Leader path: compute and save, returning a fresh hit result. */
    private function leaderComputeAndSave(KeyInterface $key): ValueResultInterface
    {
        $loaded = $this->loader->resolve($key);
        $this->save($key, $loaded); // sets hard TTL (with jitter) and stores value
        $this->metrics?->inc('cache_fill');

        // recompute times deterministically after save
        $now = time();
        $hard = $now + $this->hardTtlSec;
        $soft = $hard - $this->precomputeSec;
        if ($soft < $now) {
            $soft = $now;
        }

        return ValueResult::hit($loaded, $now, $soft);
    }

    /** Follower: try to serve stale value while leader holds the lock. */
    private function tryFollowerServeStale(KeyInterface $key): ?ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());
        $item->setInvalidationMethod(Invalidation::OLD); // serve previous value if locked by another process
        $stale = $item->get();
        if ($stale !== null) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'stale']);
            return ValueResult::stale($stale, $createdAt, $softAt);
        }
        return null;
    }

    /** Follower: short wait for leader to finish, then try to return fresh. */
    private function tryFollowerWaitFresh(KeyInterface $key): ?ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());
        $item->setInvalidationMethod(Invalidation::SLEEP, 150, 6); // 6x150ms = ~900ms max wait
        $waited = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh_after_sleep']);
            return ValueResult::hit($waited, $createdAt, $softAt);
        }
        return null;
    }

    /** Last resort: compute fail-open (do not save) or return miss if fail-closed. */
    private function failOpenOrMiss(KeyInterface $key): ValueResultInterface
    {
        if ($this->failOpen) {
            $fallback = $this->loader->resolve($key);
            $now = time();
            $hard = $now + $this->hardTtlSec;
            $soft = max($now, $hard - $this->precomputeSec);
            $this->metrics?->inc('cache_miss', ['cause' => 'precompute_race']);
            return ValueResult::hit($fallback, $now, $soft); // computed value, not yet cached
        }

        $this->metrics?->inc('cache_miss', ['cause' => 'precompute_race_fail_closed']);
        return ValueResult::miss();
    }

    public function put(KeyInterface $key, mixed $value): void
    {
        $this->save($key, $value);
        $this->metrics?->inc('cache_put');
    }

    private function save(KeyInterface $key, mixed $value): void
    {
        $item = $this->pool->getItem($key->toString());
        // Stash TTL is hard TTL; add jitter if configured.
        // NOTE: Stash's Item::executeSet subtracts a further random 0..15% from
        // this TTL on save, so the *stored* expiry is not deterministic despite
        // DefaultJitter being deterministic. There is no supported way to disable
        // that in stock Stash. See https://github.com/tedious/Stash/issues/419
        $hardTtl = $this->jitter?->apply($this->hardTtlSec, $key) ?? $this->hardTtlSec;

        // PSR-6: store raw value; Stash keeps creation/expiration internally
        $item->set($value);
        $item->expiresAfter($hardTtl);
        $this->pool->save($item);
    }

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach (is_array($selectors) ? $selectors : [$selectors] as $selector) {
            if ($mode === SyncMode::ASYNC) {
                $this->dispatch(new InvalidateEvent($selector));
                continue;
            }

            // Route through the pool Item, which carries the correctly-namespaced
            // Stash key path. Calling $driver->clear($selector) directly with a
            // Key/prefix OBJECT is a no-op: Stash's makeKeyString()/normalizeKeys()
            // iterate it as an array and hit the empty/root path (FRSH-019).
            $this->item($selector)->clear();
            $this->metrics?->inc('cache_invalidate_hierarchical');
        }
    }

    public function invalidateExact(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void
    {
        $list = is_array($keys) ? $keys : [$keys];

        if ($mode === SyncMode::ASYNC) {
            foreach ($list as $key) {
                $this->dispatch(new InvalidateExactEvent($key));
            }
            return;
        }

        $driver = $this->pool->getDriver();
        if ($driver instanceof Driver\Redis) {
            // Batch the whole set into ONE DEL. Each Item carries the resolved,
            // namespaced Stash key path (the FRSH-019 seam); handing them to the
            // driver together avoids one round-trip per key (FRSH-020).
            $paths = array_map(fn (KeyInterface $key): array => $this->item($key)->keyPath(), $list);
            $driver->clearExactMany($paths);
        } else {
            // Non-Redis driver: fall back to the per-key exact clear through the Item.
            foreach ($list as $key) {
                $this->item($key)->clear(true);
            }
        }

        // One metric per invalidated key (unchanged semantics; inc() takes no count).
        for ($i = count($list); $i > 0; $i--) {
            $this->metrics?->inc('cache_invalidate');
        }
    }

    public function refresh(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            if ($mode === SyncMode::ASYNC) {
                $this->dispatch(new RefreshEvent($key));
                continue;
            }

            $this->put($key, $this->loader->resolve($key));
        }
    }

    /**
     * Fetch the pool Item for a selector as a Freshen Item — the seam invalidation
     * must go through so the driver receives the correctly-namespaced Stash key path
     * (passing a Key/prefix object straight to the driver is a silent no-op; FRSH-019).
     * getItem() always returns a Freshen\Item because the constructor pins the pool's
     * item class; the assert narrows the PSR CacheItemInterface return for PHPStan.
     */
    private function item(KeyPrefixInterface|KeyInterface $selector): Item
    {
        $item = $this->pool->getItem($selector->toString());
        \assert($item instanceof Item);
        return $item;
    }

    private function dispatch(AsyncEvent $event): void
    {
        if ($this->eventDispatcher === null) {
            throw new \LogicException('ASYNC mode requires an EventDispatcher to be provided in the constructor.');
        }
        $this->eventDispatcher->dispatch($event);
    }

    public function asPool(): \Psr\Cache\CacheItemPoolInterface
    {
        return $this->pool;
    }
}
