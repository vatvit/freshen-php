<?php

declare(strict_types=1);

namespace Freshen;

enum CacheReadState
{
    case HIT;
    case STALE;
    case MISS;
}
