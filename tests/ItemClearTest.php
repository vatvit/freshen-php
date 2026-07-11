<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\Item;
use PHPUnit\Framework\TestCase;
use Stash\Driver\Ephemeral;
use Stash\Interfaces\DriverInterface;
use Stash\Pool;

/**
 * Freshen\Item::clear() adds an exact (non-hierarchical) delete mode Stash lacks:
 *
 *  - clear(false) (default) delegates to parent::clear() — hierarchical, also
 *    invalidating child keys.
 *  - clear(true) bypasses the parent and calls driver->clear($key, true) so only
 *    the direct item is removed (Stash #345 / #369).
 *
 * The deterministic-TTL half of Item is covered by ItemDeterministicTtlTest.
 */
final class ItemClearTest extends TestCase
{
    /** Build a Freshen\Item wired to $driver for key $key (narrowed for the exact-mode API). */
    private function itemFor(DriverInterface $driver, string $key): Item
    {
        $pool = new Pool($driver);
        $pool->setItemClass(Item::class);
        $item = $pool->getItem($key);
        if (!$item instanceof Item) {
            self::fail('pool did not build a Freshen\\Item');
        }
        return $item;
    }

    public function testKeyPathReturnsTheNamespacedStashPath(): void
    {
        // keyPath() exposes the resolved path array the driver deletes under — used
        // to batch invalidateExact([...]) into one DEL (FRSH-020). Stash builds it:
        // index 0 is the storage-type slot ('cache'; the lock path swaps it to 'sp'),
        // index 1 the pool namespace, then the '/'-split key segments.
        $item = $this->itemFor(new Ephemeral(), 'product/detail/7');

        self::assertSame(['cache', 'stash_default', 'product', 'detail', '7'], $item->keyPath());
    }

    public function testHierarchicalClearRemovesTheStoredValue(): void
    {
        $pool = new Pool(new Ephemeral());
        $pool->setItemClass(Item::class);

        $item = $pool->getItem('product/top-sellers/42');
        $item->set('value')->expiresAfter(100);
        $pool->save($item);

        self::assertTrue($pool->getItem('product/top-sellers/42')->isHit(), 'precondition: value stored');

        // Default (exact=false) → parent::clear(), the hierarchical path.
        self::assertTrue($item->clear());

        self::assertFalse($pool->getItem('product/top-sellers/42')->isHit(), 'value must be gone after clear()');
    }

    public function testExactClearDelegatesToDriverWithTheExactFlag(): void
    {
        $driver = $this->createMock(DriverInterface::class);

        // The exact branch must call driver->clear($key, true) — the extra bool is the
        // exact flag stock Stash's clear() does not accept. Assert both args and the
        // returned bool are forwarded verbatim.
        $driver->expects($this->once())
            ->method('clear')
            ->with($this->callback(static fn ($k): bool => is_array($k)), true)
            ->willReturn(true);

        self::assertTrue($this->itemFor($driver, 'product/top-sellers/42')->clear(true));
    }

    public function testExactClearReturnsWhateverTheDriverReturns(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->once())
            ->method('clear')
            ->with($this->callback(static fn ($k): bool => is_array($k)), true)
            ->willReturn(false);

        self::assertFalse(
            $this->itemFor($driver, 'product/top-sellers/42')->clear(true),
            'exact clear returns the driver result',
        );
    }
}
