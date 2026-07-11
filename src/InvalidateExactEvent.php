<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\KeyInterface;

/**
 * Async exact-key invalidation: remove only this key, leaving hierarchical
 * neighbours intact. Routed to {@see AsyncHandler::handleInvalidateExact()}.
 */
final class InvalidateExactEvent extends AsyncEvent
{
    public function __construct(
        public KeyInterface $key,
    ) {
    }
}
