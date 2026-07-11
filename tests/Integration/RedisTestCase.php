<?php

declare(strict_types=1);

namespace Freshen\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Shared base for the live-Redis integration tests. Centralises the connect /
 * skip-when-unavailable / select-and-flush boilerplate so each test only picks a
 * database and gets a clean, ready \Redis client. Run via scripts/php-redis-it.sh
 * (REQUIREMENTS §5: no live Redis in the default unit suite).
 */
abstract class RedisTestCase extends TestCase
{
    /**
     * Connect to the integration Redis, select $db, flush it, and return the client.
     * Skips the test (rather than failing) when ext-redis or the server is absent.
     */
    protected function connectRedis(int $db): \Redis
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not loaded.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: '6379');

        $client = new \Redis();
        try {
            if (!$client->connect($host, $port)) {
                self::markTestSkipped("Could not connect to Redis at {$host}:{$port}.");
            }
        } catch (\RedisException $e) {
            self::markTestSkipped("Redis unavailable at {$host}:{$port}: {$e->getMessage()}");
        }

        $client->select($db);
        $client->flushDB();

        return $client;
    }
}
