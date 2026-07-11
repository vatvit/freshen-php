<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\KeyInterface;

/**
 * Async refresh: recompute the value via the loader and store it now.
 * Routed to {@see AsyncHandler::handleRefresh()}.
 */
final class RefreshEvent extends AsyncEvent
{
    public function __construct(
        public KeyInterface $key,
    ) {
    }
}
