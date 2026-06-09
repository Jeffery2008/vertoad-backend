<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Infrastructure\Redis\NativeRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final readonly class RedisCronLockStore implements CronLockStoreInterface
{
    private RedisClientInterface $client;

    public function __construct(RedisClientInterface|\Redis $redis, private string $prefix)
    {
        $this->client = $redis instanceof RedisClientInterface ? $redis : new NativeRedisClient($redis);
    }

    public static function fromSettings(array $settings): self
    {
        return new self(RedisClientFactory::fromSettings($settings), (string) ($settings['prefix'] ?? 'vertoad:'));
    }

    public function acquire(string $lockKey, int $ttlSeconds): bool
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Cron lock TTL seconds must be positive.');
        }

        return $this->client->setNxEx($this->prefix . $lockKey, '1', $ttlSeconds);
    }

    public function release(string $lockKey): void
    {
        $this->client->delete($this->prefix . $lockKey);
    }

    public function isLocked(string $lockKey): bool
    {
        return $this->client->exists($this->prefix . $lockKey);
    }
}
