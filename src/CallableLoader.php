<?php

declare(strict_types=1);

namespace Freshen;

use Freshen\Interface\KeyInterface;
use Freshen\Interface\LoaderInterface;

final class CallableLoader implements LoaderInterface
{
    /** @var \Closure(KeyInterface): mixed */
    private \Closure $fn;

    /** @param callable(KeyInterface): mixed $fn */
    public function __construct(callable $fn)
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    public function resolve(KeyInterface $key): mixed
    {
        return ($this->fn)($key);
    }
}
