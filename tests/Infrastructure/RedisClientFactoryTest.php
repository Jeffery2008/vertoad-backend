<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\RedisClientFactory;

final class RedisClientFactoryTest extends TestCase
{
    public function testFactoryRequiresRedisPassword(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIS_PASSWORD is required for Redis connections.');

        RedisClientFactory::fromSettings(['password' => '']);
    }

    public function testFactoryUsesPredisWhenNativeRedisExtensionIsUnavailable(): void
    {
        if (extension_loaded('redis')) {
            self::markTestSkipped('Native redis extension is loaded in this PHP runtime.');
        }

        self::assertSame('predis', RedisClientFactory::driverForSettings(['driver' => 'auto']));
    }

    public function testFactoryHonorsExplicitDriver(): void
    {
        self::assertSame('predis', RedisClientFactory::driverForSettings(['driver' => 'predis']));
        self::assertSame('phpredis', RedisClientFactory::driverForSettings(['driver' => 'phpredis']));
        self::assertSame('socket', RedisClientFactory::driverForSettings(['driver' => 'socket']));
    }

    public function testPredisParametersAvoidConnectCommandAuthentication(): void
    {
        self::assertSame([
            'host' => 'redis.example',
            'port' => 6380,
            'timeout' => 5.5,
            'read_write_timeout' => 6.5,
        ], RedisClientFactory::predisParameters([
            'host' => 'redis.example',
            'port' => 6380,
            'password' => 'secret',
            'database' => 4,
            'timeout_seconds' => 5.5,
            'read_timeout_seconds' => 6.5,
        ]));
    }

    public function testFactoryCanBuildPredisClientWithoutOpeningConnection(): void
    {
        $client = RedisClientFactory::fromSettings([
            'driver' => 'predis',
            'host' => 'redis.example',
            'port' => 6380,
            'password' => 'secret',
        ]);

        self::assertInstanceOf(\VertoAD\Infrastructure\Redis\PredisRedisClient::class, $client);
    }

    public function testFactoryUsesSocketDriverSettings(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Redis');

        RedisClientFactory::fromSettings([
            'driver' => 'socket',
            'host' => '127.0.0.1',
            'port' => 1,
            'password' => 'secret',
            'database' => 2,
            'timeout_seconds' => 0.01,
            'read_timeout_seconds' => 0.01,
        ]);
    }

    public function testFactoryReportsMissingPhpRedisExtensionWhenExplicitlyRequested(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('Redis class is already defined in this process.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Redis extension is required for phpredis connections.');

        RedisClientFactory::fromSettings([
            'driver' => 'phpredis',
            'password' => 'secret',
        ]);
    }
}
