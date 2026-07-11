<?php

declare(strict_types=1);

namespace Freshen\Driver;

use Stash\Driver\Redis as BaseRedis;

/**
 * Extension of Stash's built-in Redis driver that:
 *  1) Allows injecting a ready Redis connection via setOptions(['connection' => \Redis|\RedisCluster]).
 *  2) Uses NX when storing data for keys whose first segment equals 'sp' (single-put).
 *  3) Adds an exact (non-hierarchical) delete mode to clear().
 *
 * Why this class exists — it patches two documented Stash limitations:
 *  - Non-atomic lock / single-flight: stock Item::lock() -> storeData() is an
 *    unconditional SET, so every concurrent caller "wins" the lock and no real
 *    stampede protection happens. See Stash issues:
 *      https://github.com/tedious/Stash/issues/203 (lock() ignored, callers fight)
 *      https://github.com/tedious/Stash/issues/317
 *      https://github.com/tedious/Stash/issues/107
 *      https://github.com/tedious/Stash/issues/38
 *  - No exact delete: stock Driver::clear($key) always increments the path index,
 *    invalidating all child keys (hierarchical only). See:
 *      https://github.com/tedious/Stash/issues/345 (clear a key also clears subkeys)
 *      https://github.com/tedious/Stash/issues/369 (clear = index increment)
 *
 * Notes:
 *  - This code targets the PECL phpredis client, as Stash’s Redis driver is based on it.
 *  - If your project uses a custom Stash serializer/compressor, mirror that in the NX branch (see comment).
 */
final class Redis extends BaseRedis
{
    /**
     * setOptions override:
     *  - Accepts ['connection' => \Redis|\RedisCluster] to reuse an existing client.
     *  - Falls back to parent behavior for standard options (servers, password, prefix, database, …).
     */
    public function setOptions(array $options = []): void
    {
        if (array_key_exists('connection', $options) && $options['connection'] !== null) {
            $conn = $options['connection'];
            if (!($conn instanceof \Redis) && !($conn instanceof \RedisCluster)) {
                throw new \InvalidArgumentException('Option "connection" must be an instance of \Redis or \RedisCluster.');
            }
            // Reuse the injected client as-is, and RETURN: parent::setOptions() would
            // otherwise build a fresh localhost \Redis and overwrite $this->redis,
            // silently discarding the client we were given.
            $this->redis = $conn;
            return;
        }

        // No external connection supplied — let the parent connect (servers, auth, database, …).
        parent::setOptions($options);
    }

    public function storeData($key, $data, $expiration): bool
    {
        if (is_array($key) && isset($key[0]) && $key[0] === 'sp') {
            return $this->storeAsLock($key, $data, $expiration);
        }
        return parent::storeData($key, $data, $expiration);
    }

    /**
     * Store value using Redis SET NX (+ TTL) to emulate a lock/single-put.
     * Returns true only if the key did not exist and was set.
     *
     * This is the atomic single-flight guarantee stock Stash lacks — Item::lock()
     * otherwise does an unconditional SET and always returns true.
     * See https://github.com/tedious/Stash/issues/203
     *
     * $expiration is an ABSOLUTE unix timestamp — Stash's storeData() contract
     * (the base driver uses EXAT). We convert it to a relative TTL for SET…EX and
     * clamp the lock lifetime. Treating it as a relative TTL (the old bug) made
     * every real Item::lock() throw, since it passes time()+stampede_ttl. FRSH-019.
     */
    private function storeAsLock(array $key, mixed $data, int $expiration): bool
    {
        $ttl = $expiration - time();
        if ($ttl <= 0) {
            // Already expired — no lock to take. Report "not acquired".
            return false;
        }
        if ($ttl > 300) {
            $ttl = 300; // cap the single-flight lock lifetime so a dead leader's lock self-heals
        }

        $opts = ['NX', 'EX' => $ttl];
        $ok = $this->redis->set($this->makeKeyString($key, true), $data, $opts);
        return $ok === true;
    }

    /**
     * $exact = true deletes ONLY the direct item, without incrementing the path
     * index — the exact (non-hierarchical) delete stock Stash cannot do; its
     * clear() always cascades to child keys.
     * See https://github.com/tedious/Stash/issues/345 and /issues/369
     */
    public function clear($key = null, bool $exact = false): bool
    {
        if ($key === null) {
            // Freshen invalidates by key/prefix only; it deliberately does NOT expose a
            // whole-store flush. Stash's null-key clear() maps to Redis FLUSHDB, which wipes
            // the entire database (all keys, not just cached ones). $cache->asPool()->clear()
            // reaches here.
            throw new \RuntimeException(
                'Freshen does not support flushing the whole store; invalidate by key or prefix instead.'
            );
        }

        if ($exact) {
            $keyReal = $this->makeKeyString($key);
            $this->redis->del($keyReal); // remove direct item.
            return true;
        }

        return parent::clear($key);
    }

    /**
     * Exact-delete many keys in a single DEL — the batched form of
     * clear($key, exact: true). Each element is a Stash key-path array (as held by an
     * Item, via Freshen\Item::keyPath()). Collapses N per-key DELs into one (FRSH-020).
     *
     * @param list<array<int, string>> $keys
     */
    public function clearExactMany(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $real = [];
        foreach ($keys as $key) {
            $real[] = $this->makeKeyString($key);
        }
        $this->redis->del(...$real);
    }

}
