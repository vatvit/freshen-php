<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\CallableLoader;
use Freshen\Interface\KeyInterface;
use PHPUnit\Framework\TestCase;

final class CallableLoaderTest extends TestCase
{
    /**
     * Regression: CallableLoader once had no constructor and read an undeclared
     * $this->fn, so any instance fatalled at resolve() ("null is not callable").
     * This asserts the callable is stored and invoked with the key.
     */
    public function testResolveInvokesCallableWithKeyAndReturnsItsResult(): void
    {
        $key = $this->createMock(KeyInterface::class);
        $received = null;

        $loader = new CallableLoader(function (KeyInterface $k) use (&$received) {
            $received = $k;
            return 'resolved';
        });

        $result = $loader->resolve($key);

        $this->assertSame('resolved', $result);
        $this->assertSame($key, $received, 'the key must be passed through to the callable');
    }

    public function testAcceptsAnyCallableNotJustClosures(): void
    {
        $key = $this->createMock(KeyInterface::class);

        // An invokable object is a valid callable and must be accepted.
        $callable = new class {
            public function __invoke(KeyInterface $key): string
            {
                return 'from-invokable';
            }
        };

        $loader = new CallableLoader($callable);

        $this->assertSame('from-invokable', $loader->resolve($key));
    }
}
