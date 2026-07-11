<?php

declare(strict_types=1);

namespace Freshen;

/**
 * Extends Stash's Item to patch two Stash behaviours Freshen requires:
 *
 *  1) Exact (non-hierarchical) clear() — stock Stash Item::clear() has no exact
 *     mode; clearing a key always invalidates its child keys too.
 *     See https://github.com/tedious/Stash/issues/345 and /issues/369
 *
 *  2) Deterministic TTL — stock Stash Item::executeSet() subtracts a *random*
 *     0..15% from every stored TTL, so the stored expiry is non-deterministic even
 *     when an exact TTL is given via expiresAfter(). Freshen owns jitter itself
 *     (deterministic DefaultJitter, applied before save), so this override drops the
 *     random block and stores the TTL verbatim.
 *     See https://github.com/tedious/Stash/issues/419 (and /issues/305)
 */
class Item extends \Stash\Item {

    public function clear(bool $exact = false): bool
    {
        if ($exact) {
            return $this->driver->clear($this->key, $exact);
        }

        return parent::clear();
    }

    /**
     * The item's resolved, namespaced Stash key-path array (the authoritative path
     * the driver deletes under). Exposed so a caller can batch an exact delete of
     * several items into a single driver round-trip without reconstructing the path
     * by hand (see Freshen\Driver\Redis::clearExactMany, FRSH-020).
     *
     * @return array<int, string>
     */
    public function keyPath(): array
    {
        /** @var array<int, string> $key */
        $key = $this->key;
        return $key;
    }

    /**
     * Copy of \Stash\Item::executeSet() (tedivm/stash v1.2.1) with the random TTL
     * reduction removed, so the TTL set by expiresAfter() — already jittered
     * deterministically by Freshen\DefaultJitter — is stored verbatim.
     *
     * Re-check on any Stash upgrade. The only intentional difference from the parent
     * is the removed `if ($cacheTime > 0) { random_int(...) }` block.
     */
    protected function executeSet(mixed $data, int|\DateTimeInterface|null $time): bool
    {
        if ($this->isDisabled() || !isset($this->key)) {
            return false;
        }

        $store = array();
        $store['return'] = $data;
        $store['createdOn'] = time();

        if (isset($time) && (($time instanceof \DateTime) || ($time instanceof \DateTimeInterface))) {
            $expiration = $time->getTimestamp();
            $cacheTime = $expiration - $store['createdOn'];
        } else {
            $cacheTime = self::$cacheTime;
        }

        $expiration = $store['createdOn'] + $cacheTime;

        // Stock Stash subtracts random_int(0, floor($cacheTime * .15)) here — OMITTED
        // on purpose: Freshen applies deterministic jitter before save (PARITY §9 / #419).

        if ($this->stampedeRunning === true) {
            $spkey = $this->key;
            $spkey[0] = 'sp'; // change "cache" data namespace to stampede namespace
            $this->driver->clear($spkey);
            $this->stampedeRunning = false;
        }

        return $this->driver->storeData($this->key, $store, $expiration);
    }

}
