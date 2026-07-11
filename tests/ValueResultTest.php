<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\ValueResult;
use PHPUnit\Framework\TestCase;

/**
 * ValueResult is the read outcome (hit / stale / miss). CacheTest exercises the
 * hit and stale factories through Cache::get; this pins the miss state and the
 * value()-on-miss guard directly.
 */
final class ValueResultTest extends TestCase
{
    public function testHit(): void
    {
        $r = ValueResult::hit('v', 1000, 1540);

        self::assertTrue($r->isHit());
        self::assertFalse($r->isStale());
        self::assertFalse($r->isMiss());
        self::assertSame('v', $r->value());
        self::assertSame(1000, $r->createdAt());
        self::assertSame(1540, $r->softExpiresAt());
    }

    public function testStale(): void
    {
        $r = ValueResult::stale('old', 900, 1400);

        self::assertTrue($r->isStale());
        self::assertFalse($r->isHit());
        self::assertFalse($r->isMiss());
        self::assertSame('old', $r->value());
        self::assertSame(900, $r->createdAt());
        self::assertSame(1400, $r->softExpiresAt());
    }

    public function testMissHasNoTimestampsAndReportsMiss(): void
    {
        $r = ValueResult::miss();

        self::assertTrue($r->isMiss());
        self::assertFalse($r->isHit());
        self::assertFalse($r->isStale());
        self::assertNull($r->createdAt());
        self::assertNull($r->softExpiresAt());
    }

    public function testValueOnMissThrows(): void
    {
        $r = ValueResult::miss();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no value (miss)');
        $r->value();
    }

    public function testHitCanCarryNullValue(): void
    {
        // A cached null is a legitimate hit — value() must return null, not throw.
        $r = ValueResult::hit(null, 1, 2);

        self::assertTrue($r->isHit());
        self::assertNull($r->value());
    }
}
