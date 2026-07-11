<?php

declare(strict_types=1);

namespace Freshen\Tests\Integration;

use Freshen\Cache;
use Freshen\CallableLoader;
use Freshen\DefaultJitter;
use Freshen\Driver\Redis as FreshenRedis;
use Freshen\Interface\KeyInterface;
use Freshen\Interface\KeyPrefixInterface;
use Freshen\Key;
use Freshen\SyncMode;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end coverage for the full Cache → Stash → live-Redis path — the seam the
 * unit tests (which mock the driver) and the driver-only integration tests never
 * exercise. Regression for FRSH-019, where all three broke against real Redis:
 *
 *   1. get() on a cold key threw "Invalid TTL" (leader-fill lock passed an absolute
 *      expiration that storeAsLock treated as a relative TTL > 300).
 *   2. invalidateExact($key, SYNC) was a no-op (a Key object handed to the driver's
 *      array-oriented clear() cleared the empty/root path).
 *   3. invalidate($key, SYNC) was a no-op (same root cause).
 *
 * Oracle is the loader call-count + the injected client's keyspace, NOT
 * $cache->get()->isMiss() — get() recomputes-and-refills on a miss and so always
 * looks like a hit, masking a broken delete.
 */
#[Group('integration')]
final class CacheRedisTest extends RedisTestCase
{
    private const DB = 6;

    private \Redis $client;
    private int $loaderCalls = 0;

    protected function setUp(): void
    {
        $this->client = $this->connectRedis(self::DB);
        $this->loaderCalls = 0;
    }

    /** A Cache whose loader counts calls and returns a per-call marker string. */
    private function newCache(): Cache
    {
        $pool = new \Stash\Pool(new FreshenRedis(['connection' => $this->client]));
        $loader = new CallableLoader(function (KeyInterface $key): string {
            $this->loaderCalls++;
            return 'value-' . $this->loaderCalls;
        });

        return new Cache($pool, $loader, hardTtlSec: 3600, precomputeSec: 60, jitter: new DefaultJitter(15));
    }

    public function testGetOnColdKeyFillsViaLoaderWithoutThrowing(): void
    {
        // Bug 1: this used to throw "Invalid TTL" on the very first get of any key.
        $cache = $this->newCache();
        $result = $cache->get(new Key('product', 'detail', 1));

        self::assertTrue($result->isHit());
        self::assertSame('value-1', $result->value());
        self::assertSame(1, $this->loaderCalls, 'loader runs exactly once to fill a cold key');
    }

    public function testSecondGetIsServedFromCacheNotRecomputed(): void
    {
        $cache = $this->newCache();
        $key = new Key('product', 'detail', 2);

        $first = $cache->get($key);
        $second = $cache->get($key);

        self::assertSame('value-1', $first->value());
        self::assertSame('value-1', $second->value(), 'second get returns the cached value');
        self::assertSame(1, $this->loaderCalls, 'a warm hit must not re-run the loader');
    }

    public function testInvalidateExactRemovesTheEntry(): void
    {
        // Bug 2: invalidateExact(SYNC) must actually delete, so the next get recomputes.
        $cache = $this->newCache();
        $key = new Key('product', 'detail', 3);

        $cache->get($key);                                  // fill: loaderCalls = 1
        self::assertGreaterThanOrEqual(1, $this->client->dbSize(), 'entry is stored');

        $cache->invalidateExact($key, SyncMode::SYNC);

        // Robust oracle: the entry is gone, so the next get must re-run the loader.
        // (dbSize is not asserted exactly — a cold fill's lock can leave a Stash
        // path-index counter behind, unrelated to the value entry.)
        $reloaded = $cache->get($key);                      // miss → recompute: loaderCalls = 2
        self::assertSame('value-2', $reloaded->value(), 'entry was gone, so the loader ran again');
        self::assertSame(2, $this->loaderCalls);
    }

    public function testInvalidateExactBatchRemovesEveryEntry(): void
    {
        // FRSH-020: invalidateExact([...], SYNC) batches into one DEL and must remove
        // *every* listed key (next get of each recomputes).
        $cache = $this->newCache();
        $keys = [
            new Key('product', 'batch', 1),
            new Key('product', 'batch', 2),
            new Key('product', 'batch', 3),
        ];
        foreach ($keys as $key) {
            $cache->get($key);                              // fill: loaderCalls 1,2,3
        }
        self::assertSame(3, $this->loaderCalls);

        $cache->invalidateExact($keys, SyncMode::SYNC);     // one batched DEL

        foreach ($keys as $key) {
            $cache->get($key);                              // each recomputes: 4,5,6
        }
        self::assertSame(6, $this->loaderCalls, 'every batched key was removed, so all three recomputed');
    }

    public function testHierarchicalInvalidateDropsTheKey(): void
    {
        // Bug 3: invalidate(SYNC) on a key must invalidate it (next get recomputes).
        $cache = $this->newCache();
        $key = new Key('product', 'list', 4);

        $cache->get($key);                                  // loaderCalls = 1
        $cache->invalidate($key, SyncMode::SYNC);

        $reloaded = $cache->get($key);                      // must recompute
        self::assertSame('value-2', $reloaded->value(), 'hierarchical invalidation drops the entry');
        self::assertSame(2, $this->loaderCalls);
    }

    public function testHierarchicalInvalidateByPrefixDropsWholeSubtree(): void
    {
        // A prefix selector must drop every entry beneath it in one call.
        $cache = $this->newCache();
        $a = new Key('catalog', 'item', 10);
        $b = new Key('catalog', 'item', 20);

        $cache->get($a);                                    // loaderCalls = 1
        $cache->get($b);                                    // loaderCalls = 2

        $prefix = new class implements KeyPrefixInterface {
            /** @return list<string> */
            public function segments(): array { return ['catalog', 'item']; }
            public function toString(): string { return 'catalog/item'; }
        };
        $cache->invalidate($prefix, SyncMode::SYNC);

        // Both children must now be cold → each get re-runs the loader.
        $cache->get($a);                                    // loaderCalls = 3
        $cache->get($b);                                    // loaderCalls = 4
        self::assertSame(4, $this->loaderCalls, 'both entries under the prefix were invalidated');
    }
}
