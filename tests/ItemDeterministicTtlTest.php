<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\Item;
use PHPUnit\Framework\TestCase;
use Stash\Driver\Ephemeral;
use Stash\Pool;

final class ItemDeterministicTtlTest extends TestCase
{
    /**
     * Freshen\Item::executeSet() must store the exact TTL from expiresAfter(), without
     * Stash's random 0..15% reduction — so the same key always stores the same TTL
     * (PARITY §9 / Stash #419). Stock Stash would yield a delta in [85, 100].
     */
    public function testStoredTtlIsExactWithNoRandomReduction(): void
    {
        $pool = new Pool(new Ephemeral());
        $pool->setItemClass(Item::class);

        $item = $pool->getItem('product/top-sellers/42');
        $item->set('value')->expiresAfter(100);
        $pool->save($item);

        $fresh = $pool->getItem('product/top-sellers/42');
        $creation = $fresh->getCreation();
        $expiration = $fresh->getExpiration();

        self::assertInstanceOf(\DateTimeInterface::class, $creation);
        self::assertInstanceOf(\DateTimeInterface::class, $expiration);
        self::assertSame(100, $expiration->getTimestamp() - $creation->getTimestamp());
    }
}
