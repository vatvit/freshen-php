<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\DefaultJitter;
use Freshen\Key;
use Freshen\Interface\KeyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Freshen\DefaultJitter applies deterministic, per-key TTL jitter (PARITY §9):
 * same key ⇒ same TTL, symmetric in [ttl−δ, ttl+δ] with δ = floor(ttl·pct/100),
 * result floored to ≥1, and a δ==0 short-circuit.
 *
 * Assertions are property-based (determinism, bounds, floor, δ==0) rather than a
 * copy of the CRC-32 offset formula, so the test locks the observable contract
 * without merely restating the implementation.
 */
final class DefaultJitterTest extends TestCase
{
    private function key(string $s): KeyInterface
    {
        $k = $this->createMock(KeyInterface::class);
        $k->method('toString')->willReturn($s);
        return $k;
    }

    public function testSameKeyAlwaysProducesTheSameTtl(): void
    {
        $jitter = new DefaultJitter(15);
        $key = $this->key('product/top-sellers/42');

        $first = $jitter->apply(600, $key);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame($first, $jitter->apply(600, $key), 'jitter must be deterministic per key');
        }
    }

    #[DataProvider('ttlAndPercentProvider')]
    public function testResultStaysWithinSymmetricDeltaBand(int $ttl, int $percent): void
    {
        $jitter = new DefaultJitter($percent);
        $delta = (int) floor($ttl * $percent / 100);

        // Sweep many distinct keys; every result must land inside [ttl−δ, ttl+δ] (and ≥1).
        for ($i = 0; $i < 200; $i++) {
            $result = $jitter->apply($ttl, $this->key("k/$i"));
            self::assertGreaterThanOrEqual(max(1, $ttl - $delta), $result);
            self::assertLessThanOrEqual($ttl + $delta, $result);
        }
    }

    /** @return array<string, array{0:int,1:int}> */
    public static function ttlAndPercentProvider(): array
    {
        return [
            'default 15%'   => [600, 15],
            'custom 50%'    => [600, 50],
            'small ttl 10%' => [30, 10],
        ];
    }

    public function testJitterActuallyVariesAcrossKeys(): void
    {
        $jitter = new DefaultJitter(15);

        $values = [];
        for ($i = 0; $i < 50; $i++) {
            $values[] = $jitter->apply(600, $this->key("vary/$i"));
        }

        // A deterministic-but-spread jitter must not collapse every key to one value.
        self::assertGreaterThan(1, count(array_unique($values)), 'jitter should spread TTLs across keys');
    }

    public function testResultIsFlooredToAtLeastOne(): void
    {
        // ttl=2, percent=100 => δ=2, offset ∈ [−2,+2] => raw result can hit 0; must floor to 1.
        $jitter = new DefaultJitter(100);
        for ($i = 0; $i < 200; $i++) {
            self::assertGreaterThanOrEqual(1, $jitter->apply(2, $this->key("floor/$i")));
        }
    }

    public function testZeroDeltaWhenPercentIsZeroReturnsTtlUnchanged(): void
    {
        // percent=0 => δ=0 => short-circuit returns max(1, ttl) with no offset.
        $jitter = new DefaultJitter(0);
        $key = $this->key('anything');

        self::assertSame(600, $jitter->apply(600, $key));
        self::assertSame(1, $jitter->apply(1, $key));
    }

    public function testZeroDeltaWhenTtlTooSmallForPercentReturnsTtl(): void
    {
        // ttl=6, percent=15 => floor(6*15/100)=floor(0.9)=0 => δ==0 branch => returns 6.
        $jitter = new DefaultJitter(15);
        self::assertSame(6, $jitter->apply(6, $this->key('tiny')));
    }

    public function testCustomPercentWidensTheBand(): void
    {
        $narrow = new DefaultJitter(1);
        $wide   = new DefaultJitter(90);

        // With δ=6 (1%) results hug 600; with δ=540 (90%) they can range far. Assert the
        // wide config can produce a value outside the narrow band for at least one key.
        $foundOutsideNarrow = false;
        for ($i = 0; $i < 100; $i++) {
            $k = $this->key("band/$i");
            $narrow->apply(600, $k); // exercised for coverage/determinism symmetry
            $w = $wide->apply(600, $k);
            if ($w < 594 || $w > 606) {
                $foundOutsideNarrow = true;
                break;
            }
        }
        self::assertTrue($foundOutsideNarrow, 'a 90% band must exceed the 1% band for some key');
    }

    public function testWorksWithARealKeyInstance(): void
    {
        // Integration with the real Key (its toString feeds the CRC) — still deterministic.
        $jitter = new DefaultJitter(15);
        $key = new Key('product', 'detail', 'sku-1', 'v2', 'en_US');

        self::assertSame($jitter->apply(600, $key), $jitter->apply(600, $key));
    }
}
