<?php

declare(strict_types=1);

namespace Freshen\Interface;

interface LoaderInterface
{
    public function resolve(KeyInterface $key): mixed;
}
