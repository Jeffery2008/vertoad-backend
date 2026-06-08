<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

use Predis\Client;

final class RedisClientFactory
{
    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): RedisClientInterface
    {
        $password = (string) ($settings['password'] ?? '');
        if ($password === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for Redis connections.');
        }

        $driver = self::driverForSettings($settings);
        if ($driver === 'socket') {
            return SocketRedisClient::fromSettings($settings);
        }

        if ($driver === 'phpredis') {
            if (!class_exists(\Redis::class)) {
                throw new \RuntimeException('The Redis extension is required for phpredis connections.');
            }

            $redis = new \Redis();
            $redis->connect(
                (string) ($settings['host'] ?? '127.0.0.1'),
                (int) ($settings['port'] ?? 6379),
                (float) ($settings['timeout_seconds'] ?? 2.0),
            );
            $redis->auth($password);

            $database = (int) ($settings['database'] ?? 0);
            if ($database > 0) {
                $redis->select($database);
            }

            return new NativeRedisClient($redis);
        }

        return new PredisRedisClient(new PredisCommandClient(new Client(self::predisParameters($settings))), $password, (int) ($settings['database'] ?? 0));
    }

    /** @param array<string, mixed> $settings */
    public static function predisParameters(array $settings): array
    {
        return [
            'host' => (string) ($settings['host'] ?? '127.0.0.1'),
            'port' => (int) ($settings['port'] ?? 6379),
            'timeout' => (float) ($settings['timeout_seconds'] ?? 2.0),
            'read_write_timeout' => (float) ($settings['read_timeout_seconds'] ?? 2.0),
        ];
    }

    /** @param array<string, mixed> $settings */
    public static function driverForSettings(array $settings): string
    {
        $driver = (string) ($settings['driver'] ?? 'auto');
        if ($driver === 'auto') {
            return extension_loaded('redis') ? 'phpredis' : 'predis';
        }

        return $driver;
    }
}
