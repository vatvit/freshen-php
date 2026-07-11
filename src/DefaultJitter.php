<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\JitterInterface;
use Freshen\Interface\KeyInterface;

/**
 * Deterministic per-key TTL jitter (same key => same TTL), symmetric in [-delta, +delta].
 *
 * Caveat when used behind Stash: Stash's Item::executeSet applies its own
 * *random* 0..15% TTL reduction on save, on top of this deterministic value, so
 * the effective stored TTL is not deterministic. Stash has no supported switch to
 * disable that. See https://github.com/tedious/Stash/issues/419 (and /issues/305).
 */
final class DefaultJitter implements JitterInterface
{
    public function __construct(private int $percent = 15)
    {
    }

    public function apply(int $ttlSec, KeyInterface $key): int
    {
        // Deterministic jitter based on key hash in range [-delta, +delta].
        $delta = max(0, (int)floor($ttlSec * $this->percent / 100));
        if ($delta === 0) return max(1, $ttlSec);
        $h = crc32($key->toString());
        $offset = (int)($h % (2 * $delta + 1)) - $delta;
        return max(1, $ttlSec + $offset);
    }
}
