<?php

declare(strict_types=1);

namespace Freshen;

/**
 * Worker side of async invalidation/refresh: consumes an {@see AsyncEvent} and
 * drives the {@see Cache} synchronously. Each async operation has its own event
 * class and its own handler method, so a PSR-14 listener provider routes by
 * event class alone — no runtime discriminator switch:
 *
 *   $provider->addListener(InvalidateEvent::class,      [$handler, 'handleInvalidation']);
 *   $provider->addListener(InvalidateExactEvent::class, [$handler, 'handleInvalidateExact']);
 *   $provider->addListener(RefreshEvent::class,         [$handler, 'handleRefresh']);
 */
class AsyncHandler {

    public function __construct(
        private Cache $cache,
    ) {}

    public function handleInvalidation(InvalidateEvent $event): void
    {
        $this->cache->invalidate($event->key, SyncMode::SYNC);
    }

    public function handleInvalidateExact(InvalidateExactEvent $event): void
    {
        $this->cache->invalidateExact($event->key, SyncMode::SYNC);
    }

    public function handleRefresh(RefreshEvent $event): void
    {
        $this->cache->refresh($event->key, SyncMode::SYNC);
    }
}
