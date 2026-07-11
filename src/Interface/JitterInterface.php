<?php

declare(strict_types=1);

namespace Freshen\Interface;

interface JitterInterface
{
    public function apply(int $ttlSec, KeyInterface $key): int;
}
