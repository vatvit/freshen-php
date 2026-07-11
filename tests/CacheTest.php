<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\Cache;
use Freshen\InvalidateEvent;
use Freshen\InvalidateExactEvent;
use Freshen\RefreshEvent;
use Freshen\Interface\KeyInterface;
use Freshen\Interface\KeyPrefixInterface;
use Freshen\Interface\LoaderInterface;
use Freshen\Interface\JitterInterface;
use Freshen\Interface\MetricsInterface;
use Freshen\SyncMode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stash\Interfaces\ItemInterface;
use Stash\Interfaces\PoolInterface as StashPoolInterface;
use Stash\Interfaces\DriverInterface as StashDriverInterface;
use Stash\Invalidation;

final class CacheTest extends TestCase
{
    // --- Mock factories (keep the createMock noise out of the test bodies) ---

    private function newKey(string $id = 'k'): KeyInterface
    {
        $key = $this->createMock(KeyInterface::class);
        $key->method('toString')->willReturn($id);
        return $key;
    }

    private function newPool(): StashPoolInterface&MockObject
    {
        return $this->createMock(StashPoolInterface::class);
    }

    private function newItem(): ItemInterface&MockObject
    {
        return $this->createMock(ItemInterface::class);
    }

    private function newLoader(): LoaderInterface&MockObject
    {
        return $this->createMock(LoaderInterface::class);
    }

    private function newJitter(): JitterInterface&MockObject
    {
        return $this->createMock(JitterInterface::class);
    }

    private function newDate(int $ts): \DateTime
    {
        return (new \DateTime())->setTimestamp($ts);
    }

    // --- Stash get()-flow scaffolding (Cache::get calls getItem once per stage) ---

    /** Expect getItem($key) to be called once per supplied item, in order. */
    private function expectGetItems(StashPoolInterface&MockObject $pool, string $key, ItemInterface ...$items): void
    {
        $pool->expects($this->exactly(count($items)))
            ->method('getItem')
            ->with($key)
            ->willReturnOnConsecutiveCalls(...$items);
    }

    /** Stage 1 item: a fresh hit inside the soft window. */
    private function configureFreshHit(ItemInterface&MockObject $item, mixed $value, int $created, int $expiration): void
    {
        $item->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn(true);
        $item->method('getCreation')->willReturn($this->newDate($created));
        $item->method('getExpiration')->willReturn($this->newDate($expiration));
    }

    /** Stage 1 item: a fast-path miss that then wins ($winsLock) or loses the single-flight lock. */
    private function configureInitialMiss(ItemInterface&MockObject $item, bool $winsLock): void
    {
        $item->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $item->method('get')->willReturn(null);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->once())->method('lock')->willReturn($winsLock);
    }

    /** Stage 3 item (follower): serve-stale attempt. $value === null means no stale value. */
    private function configureStale(ItemInterface&MockObject $item, mixed $value, ?int $created = null, ?int $expiration = null): void
    {
        $item->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::OLD);
        $item->method('get')->willReturn($value);
        if ($created !== null) {
            $item->method('getCreation')->willReturn($this->newDate($created));
        }
        if ($expiration !== null) {
            $item->method('getExpiration')->willReturn($this->newDate($expiration));
        }
    }

    /** Stage 4 item (follower): short-wait attempt, hitting or still missing. */
    private function configureWait(ItemInterface&MockObject $item, mixed $value, bool $isHit, ?int $created = null, ?int $expiration = null): void
    {
        $item->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::SLEEP, 150, 6);
        $item->method('get')->willReturn($value);
        $item->method('isHit')->willReturn($isHit);
        if ($created !== null) {
            $item->method('getCreation')->willReturn($this->newDate($created));
        }
        if ($expiration !== null) {
            $item->method('getExpiration')->willReturn($this->newDate($expiration));
        }
    }

    // --- Tests ---

    public function testGetFreshHit(): void
    {
        $pool = $this->newPool();
        $item = $this->newItem();
        $key = $this->newKey('fresh');

        $this->expectGetItems($pool, 'fresh', $item);
        $this->configureFreshHit($item, 'value', 1_000, 1_600);

        $cache = new Cache($pool, $this->newLoader(), 600, 60, $this->newJitter());
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('value', $res->value());
        $this->assertSame(1_000, $res->createdAt());
        $this->assertSame(1_600 - 60, $res->softExpiresAt());
    }

    public function testLeaderComputeAndSaveOnMissWithLock(): void
    {
        $pool = $this->newPool();
        $itemInitial = $this->newItem();
        $itemForSave = $this->newItem();
        $key = $this->newKey('leader');

        $this->expectGetItems($pool, 'leader', $itemInitial, $itemForSave);
        $this->configureInitialMiss($itemInitial, winsLock: true);

        // Save path
        $itemForSave->expects($this->once())->method('set')->with('loaded');
        $itemForSave->expects($this->once())->method('expiresAfter')->with(600);
        $pool->expects($this->once())->method('save')->with($itemForSave);

        $loader = $this->newLoader();
        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('loaded');

        $jitter = $this->newJitter();
        $jitter->method('apply')->with(600, $key)->willReturn(600);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('loaded', $res->value());
    }

    public function testFollowerServeStaleWhenLockedByAnother(): void
    {
        $pool = $this->newPool();
        $itemInitial = $this->newItem();
        $itemStale = $this->newItem();
        $key = $this->newKey('stale');

        $this->expectGetItems($pool, 'stale', $itemInitial, $itemStale);
        $this->configureInitialMiss($itemInitial, winsLock: false);
        $this->configureStale($itemStale, 'stale-value', 1_000, 1_600);

        $cache = new Cache($pool, $this->newLoader(), 600, 60, $this->newJitter());
        $res = $cache->get($key);

        $this->assertTrue($res->isStale());
        $this->assertSame('stale-value', $res->value());
        $this->assertSame(1_000, $res->createdAt());
        $this->assertSame(1_600 - 60, $res->softExpiresAt());
    }

    public function testFollowerWaitFreshAfterShortSleep(): void
    {
        $pool = $this->newPool();
        $itemInitial = $this->newItem();
        $itemStale = $this->newItem();
        $itemWait = $this->newItem();
        $key = $this->newKey('sleep');

        $this->expectGetItems($pool, 'sleep', $itemInitial, $itemStale, $itemWait);
        $this->configureInitialMiss($itemInitial, winsLock: false);
        $this->configureStale($itemStale, null); // no stale value -> fall through to wait
        $this->configureWait($itemWait, 'fresh-after-wait', isHit: true, created: 2_000, expiration: 2_600);

        $cache = new Cache($pool, $this->newLoader(), 600, 60, $this->newJitter());
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('fresh-after-wait', $res->value());
        $this->assertSame(2_000, $res->createdAt());
        $this->assertSame(2_600 - 60, $res->softExpiresAt());
    }

    public function testFailOpenComputeWhenLeaderNotAvailable(): void
    {
        $pool = $this->newPool();
        $itemInitial = $this->newItem();
        $itemStale = $this->newItem();
        $itemWait = $this->newItem();
        $key = $this->newKey('race');

        $this->expectGetItems($pool, 'race', $itemInitial, $itemStale, $itemWait);
        $this->configureInitialMiss($itemInitial, winsLock: false);
        $this->configureStale($itemStale, null);          // no stale
        $this->configureWait($itemWait, null, isHit: false); // wait yields nothing

        $loader = $this->newLoader();
        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('fallback');

        // failOpen defaults to true.
        $cache = new Cache($pool, $loader, 600, 60, $this->newJitter());
        $res = $cache->get($key);

        $this->assertTrue($res->isHit(), 'Fail-open should produce a hit-like result with computed value');
        $this->assertSame('fallback', $res->value());
    }

    public function testFailClosedReturnsMissWhenLeaderRaceLost(): void
    {
        $pool = $this->newPool();
        $itemInitial = $this->newItem();
        $itemStale = $this->newItem();
        $itemWait = $this->newItem();
        $key = $this->newKey('closed');

        $this->expectGetItems($pool, 'closed', $itemInitial, $itemStale, $itemWait);
        $this->configureInitialMiss($itemInitial, winsLock: false);
        $this->configureStale($itemStale, null);
        $this->configureWait($itemWait, null, isHit: false);

        $loader = $this->newLoader();
        // fail-closed: the loader must NOT be consulted; result is a miss.
        $loader->expects($this->never())->method('resolve');

        $cache = new Cache($pool, $loader, 600, 60, $this->newJitter(), null, null, false);
        $res = $cache->get($key);

        $this->assertTrue($res->isMiss(), 'fail-closed must return a miss, not compute');
    }

    public function testPutSavesWithJitteredTtl(): void
    {
        $pool = $this->newPool();
        $item = $this->newItem();
        $key = $this->newKey('put');

        $pool->expects($this->once())->method('getItem')->with('put')->willReturn($item);
        $item->expects($this->once())->method('set')->with('v');
        $item->expects($this->once())->method('expiresAfter')->with(555);
        $pool->expects($this->once())->method('save')->with($item);

        $jitter = $this->newJitter();
        $jitter->method('apply')->with(600, $key)->willReturn(555);

        $cache = new Cache($pool, $this->newLoader(), 600, 60, $jitter);
        $cache->put($key, 'v');
        $this->addToAssertionCount(1); // if no exceptions, it's fine
    }

    public function testInvalidateAsyncAndSync(): void
    {
        $loader = $this->newLoader();
        $jitter = $this->newJitter();
        $metrics = $this->createMock(MetricsInterface::class);
        $selector = $this->newKey('sel');

        // ASYNC invalidate should dispatch an InvalidateEvent (carrying the selector)
        // and not touch the driver.
        $driverA = $this->createMock(StashDriverInterface::class);
        $poolA = $this->newPool();
        $poolA->method('getDriver')->willReturn($driverA);
        $dispatcherA = $this->createMock(EventDispatcherInterface::class);
        $dispatcherA->expects($this->once())->method('dispatch')
            ->with($this->callback(
                fn (object $e): bool => $e instanceof InvalidateEvent && $e->key === $selector,
            ));
        $driverA->expects($this->never())->method('clear');
        (new Cache($poolA, $loader, 600, 60, $jitter, $dispatcherA, $metrics))
            ->invalidate($selector, SyncMode::ASYNC);

        // SYNC invalidate must go through the pool Item (Freshen\Item::clear()),
        // NOT driver->clear($keyObject) — the latter is a silent no-op because Stash
        // iterates the Key object as an array (FRSH-019). Hierarchical == clear().
        $itemB = $this->createMock(\Freshen\Item::class);
        $itemB->expects($this->once())->method('clear')->with();
        $poolB = $this->newPool();
        $poolB->method('getItem')->with('sel')->willReturn($itemB);
        (new Cache($poolB, $loader, 600, 60, $jitter, null, $metrics))
            ->invalidate($selector, SyncMode::SYNC);

        // invalidateExact SYNC must call Freshen\Item::clear(true) (exact delete).
        $itemC = $this->createMock(\Freshen\Item::class);
        $itemC->expects($this->once())->method('clear')->with(true);
        $poolC = $this->newPool();
        $poolC->method('getItem')->with('sel')->willReturn($itemC);
        (new Cache($poolC, $loader, 600, 60, $jitter, null, $metrics))
            ->invalidateExact($selector, SyncMode::SYNC);
    }

    public function testAsyncInvalidateAcceptsAKeyPrefixSelector(): void
    {
        // Regression (FRSH-008 → FRSH-013): invalidate() accepts a KeyPrefixInterface,
        // and the ASYNC path must dispatch it without a TypeError. Before the event
        // redesign, AsyncEvent only accepted KeyInterface, so this threw.
        $prefix = $this->createMock(KeyPrefixInterface::class);
        $prefix->method('toString')->willReturn('domain/facet');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(
                fn (object $e): bool => $e instanceof InvalidateEvent && $e->key === $prefix,
            ));

        (new Cache($this->newPool(), $this->newLoader(), 600, 60, $this->newJitter(), $dispatcher))
            ->invalidate($prefix, SyncMode::ASYNC);
    }

    public function testAsyncInvalidateExactDispatchesInvalidateExactEvent(): void
    {
        $key = $this->newKey('exact');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(
                fn (object $e): bool => $e instanceof InvalidateExactEvent && $e->key === $key,
            ));

        (new Cache($this->newPool(), $this->newLoader(), 600, 60, $this->newJitter(), $dispatcher))
            ->invalidateExact($key, SyncMode::ASYNC);
    }

    public function testAsyncRefreshDispatchesRefreshEvent(): void
    {
        $key = $this->newKey('refresh');
        $loader = $this->newLoader();
        $loader->expects($this->never())->method('resolve'); // async: no inline compute
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(
                fn (object $e): bool => $e instanceof RefreshEvent && $e->key === $key,
            ));

        (new Cache($this->newPool(), $loader, 600, 60, $this->newJitter(), $dispatcher))
            ->refresh($key, SyncMode::ASYNC);
    }

    public function testAsyncInvalidateDispatchesEveryListElement(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        // A list selector must dispatch one event PER element — regression for the
        // old `return` (dispatched only the first). See FRSH-010 / FRSH-008.
        $dispatcher->expects($this->exactly(2))->method('dispatch');

        (new Cache($this->newPool(), $this->newLoader(), 600, 60, $this->newJitter(), $dispatcher))
            ->invalidate([$this->newKey('a'), $this->newKey('b')], SyncMode::ASYNC);
    }

    public function testRefreshSyncLoadsAndPuts(): void
    {
        $pool = $this->newPool();
        $item = $this->newItem();
        $pool->method('getItem')->willReturn($item);

        $loader = $this->newLoader();
        $jitter = $this->newJitter();

        $key = $this->newKey('r');
        $jitter->method('apply')->with(600, $key)->willReturn(600);

        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('rv');
        $item->expects($this->once())->method('set')->with('rv');
        $item->expects($this->once())->method('expiresAfter')->with(600);
        $pool->expects($this->once())->method('save')->with($item);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $cache->refresh($key, SyncMode::SYNC);
    }

    public function testRefreshAsyncDispatchesOneEventPerKey(): void
    {
        $loader = $this->newLoader();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        // ASYNC refresh over a list must dispatch per element and never touch the loader.
        $dispatcher->expects($this->exactly(2))->method('dispatch');
        $loader->expects($this->never())->method('resolve');

        (new Cache($this->newPool(), $loader, 600, 60, $this->newJitter(), $dispatcher))
            ->refresh([$this->newKey('a'), $this->newKey('b')], SyncMode::ASYNC);
    }

    public function testAsyncModeWithoutDispatcherThrows(): void
    {
        // No EventDispatcher provided → any ASYNC operation must fail fast.
        $cache = new Cache($this->newPool(), $this->newLoader(), 600, 60, $this->newJitter());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ASYNC mode requires an EventDispatcher');
        $cache->invalidate($this->newKey('x'), SyncMode::ASYNC);
    }

    public function testAsPoolReturnsTheUnderlyingPool(): void
    {
        $pool = $this->newPool();
        $cache = new Cache($pool, $this->newLoader(), 600, 60, $this->newJitter());

        $this->assertSame($pool, $cache->asPool());
    }

    public function testConstructorRejectsInvalidTtlAndPrecompute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cache($this->newPool(), $this->newLoader(), 0, 0, $this->newJitter()); // hardTtlSec < 1
    }

    public function testConstructorRejectsPrecomputeOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cache($this->newPool(), $this->newLoader(), 100, 200, $this->newJitter()); // precomputeSec > hardTtlSec
    }
}
