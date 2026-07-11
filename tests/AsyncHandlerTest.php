<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\AsyncHandler;
use Freshen\Cache;
use Freshen\InvalidateEvent;
use Freshen\InvalidateExactEvent;
use Freshen\Interface\KeyInterface;
use Freshen\Interface\KeyPrefixInterface;
use Freshen\RefreshEvent;
use Freshen\SyncMode;
use Psr\EventDispatcher\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * AsyncHandler is the worker side of async invalidation/refresh: it consumes an
 * AsyncEvent and drives the Cache synchronously. Each async operation has its
 * own event class, so routing is by event class alone (no `exact`/`op` switch).
 * These tests assert per-op routing and that SYNC mode is always used.
 */
final class AsyncHandlerTest extends TestCase
{
    private function key(): KeyInterface
    {
        return $this->createMock(KeyInterface::class);
    }

    public function testHandleInvalidationRoutesToInvalidateSync(): void
    {
        $key = $this->key();
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())
            ->method('invalidate')
            ->with($key, SyncMode::SYNC);
        $cache->expects($this->never())->method('invalidateExact');

        (new AsyncHandler($cache))->handleInvalidation(new InvalidateEvent($key));
    }

    public function testHandleInvalidationAcceptsAPrefixSelector(): void
    {
        // The hierarchical event carries KeyPrefixInterface|KeyInterface; the handler
        // forwards it verbatim to Cache::invalidate (which accepts the same union).
        $prefix = $this->createMock(KeyPrefixInterface::class);
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())
            ->method('invalidate')
            ->with($prefix, SyncMode::SYNC);

        (new AsyncHandler($cache))->handleInvalidation(new InvalidateEvent($prefix));
    }

    public function testHandleInvalidateExactRoutesToInvalidateExactSync(): void
    {
        $key = $this->key();
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())
            ->method('invalidateExact')
            ->with($key, SyncMode::SYNC);
        $cache->expects($this->never())->method('invalidate');

        (new AsyncHandler($cache))->handleInvalidateExact(new InvalidateExactEvent($key));
    }

    public function testHandleRefreshRoutesToRefreshSync(): void
    {
        $key = $this->key();
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())
            ->method('refresh')
            ->with($key, SyncMode::SYNC);
        $cache->expects($this->never())->method('invalidate');

        (new AsyncHandler($cache))->handleRefresh(new RefreshEvent($key));
    }

    /**
     * Acceptance criterion: an async refresh and an async invalidate on the SAME
     * key, dispatched through a SINGLE PSR-14 dispatcher, are each routed to the
     * correct handler by event class alone. This is the whole point of the
     * op-discriminator redesign — with the old single-shape AsyncEvent, one wired
     * dispatcher could not tell the two apart.
     */
    public function testSinglePsr14DispatcherRoutesRefreshAndInvalidateApart(): void
    {
        $key = $this->key();
        $cache = $this->createMock(Cache::class);
        // Exactly one of each reaches its own handler; neither crosses over.
        $cache->expects($this->once())->method('invalidate')->with($key, SyncMode::SYNC);
        $cache->expects($this->once())->method('refresh')->with($key, SyncMode::SYNC);
        $cache->expects($this->never())->method('invalidateExact');

        $handler = new AsyncHandler($cache);
        $dispatcher = $this->classRoutedDispatcher([
            InvalidateEvent::class      => [$handler, 'handleInvalidation'],
            InvalidateExactEvent::class => [$handler, 'handleInvalidateExact'],
            RefreshEvent::class         => [$handler, 'handleRefresh'],
        ]);

        // Same key, two different ops, one dispatcher.
        $dispatcher->dispatch(new RefreshEvent($key));
        $dispatcher->dispatch(new InvalidateEvent($key));
    }

    /**
     * Minimal PSR-14 dispatcher that routes strictly by concrete event class —
     * exactly how a host's listener provider would wire the three events. Kept in
     * the test so the acceptance proof does not depend on a third-party dispatcher.
     *
     * @param array<class-string, callable> $listeners
     */
    private function classRoutedDispatcher(array $listeners): EventDispatcherInterface
    {
        return new class ($listeners) implements EventDispatcherInterface {
            /** @param array<class-string, callable> $listeners */
            public function __construct(private array $listeners) {}

            public function dispatch(object $event): object
            {
                $listener = $this->listeners[$event::class] ?? null;
                if ($listener !== null) {
                    $listener($event);
                }
                return $event;
            }
        };
    }
}
