<?php

declare(strict_types=1);

namespace Freshen\Interface;

interface ValueResultInterface
{
    /** True when a value exists and is within the soft TTL. */
    public function isHit(): bool;

    /** True when a value exists but is beyond the soft TTL (stale-while-revalidate). */
    public function isStale(): bool;

    /** True when there is no value. */
    public function isMiss(): bool;

    /** Returns the value or throws if miss. */
    public function value(): mixed;

    /** Creation timestamp of the cached payload (unix seconds) or null for miss. */
    public function createdAt(): ?int;

    /** Soft-expiry timestamp (unix seconds) or null for miss. */
    public function softExpiresAt(): ?int;
}
