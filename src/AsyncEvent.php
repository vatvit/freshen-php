<?php

declare(strict_types=1);

namespace Freshen;

/**
 * Marker base for the async invalidation/refresh events (PARITY §11).
 *
 * There is one concrete event per async operation — {@see InvalidateEvent},
 * {@see InvalidateExactEvent}, {@see RefreshEvent} — so a PSR-14 listener
 * provider can route each operation to its own handler by event class alone.
 * The class *is* the operation discriminator; there is no `op`/`exact` field.
 */
abstract class AsyncEvent
{
}
