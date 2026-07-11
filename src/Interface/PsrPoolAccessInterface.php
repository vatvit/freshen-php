<?php

declare(strict_types=1);

namespace Freshen\Interface;

interface PsrPoolAccessInterface
{
    public function asPool(): \Psr\Cache\CacheItemPoolInterface;
}
