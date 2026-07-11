<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\KeyInterface;
use Freshen\Interface\KeyPrefixInterface;

/**
 * Async hierarchical invalidation: remove everything under the selector.
 *
 * The selector may be a {@see KeyPrefixInterface} (a subtree prefix) or a
 * {@see KeyInterface} (whose whole subtree is selected). Routed to
 * {@see AsyncHandler::handleInvalidation()}.
 */
final class InvalidateEvent extends AsyncEvent
{
    public function __construct(
        public KeyPrefixInterface|KeyInterface $key,
    ) {
    }
}
