<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\ValueResultInterface;

final class ValueResult implements ValueResultInterface
{
    private CacheReadState $state;
    private mixed $value;
    private ?int $createdAt;
    private ?int $softExpiresAt;

    private function __construct(CacheReadState $state, mixed $value = null, ?int $createdAt = null, ?int $softExpiresAt = null)
    {
        $this->state = $state;
        $this->value = $value;
        $this->createdAt = $createdAt;
        $this->softExpiresAt = $softExpiresAt;
    }

    /** Fresh value within the soft window. */
    public static function hit(mixed $value, int $createdAt, int $softExpiresAt): self
    {
        return new self(CacheReadState::HIT, $value, $createdAt, $softExpiresAt);
    }

    /** Stale value served beyond the soft window. */
    public static function stale(mixed $value, int $createdAt, int $softExpiresAt): self
    {
        return new self(CacheReadState::STALE, $value, $createdAt, $softExpiresAt);
    }

    /** Miss (no value available). */
    public static function miss(): self
    {
        return new self(CacheReadState::MISS);
    }

    // --- ValueResult ---

    public function isHit(): bool
    {
        return $this->state === CacheReadState::HIT;
    }

    public function isStale(): bool
    {
        return $this->state === CacheReadState::STALE;
    }

    public function isMiss(): bool
    {
        return $this->state === CacheReadState::MISS;
    }

    public function value(): mixed
    {
        if ($this->isMiss()) {
            throw new \RuntimeException('ValueResult: no value (miss).');
        }
        return $this->value;
    }

    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    public function softExpiresAt(): ?int
    {
        return $this->softExpiresAt;
    }
}
