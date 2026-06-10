<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Infrastructure\Redis\NativeRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final class RedisCronLockStore implements CronLockStoreInterface
{
    private RedisClientInterface $client;
    /** @var array<string, string> */
    private array $ownedLocks = [];

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

        $token = bin2hex(random_bytes(16));
        if (!$this->client->setNxEx($this->prefix . $lockKey, $token, $ttlSeconds)) {
            return false;
        }

        $this->ownedLocks[$lockKey] = $token;

        return true;
    }

    public function release(string $lockKey): void
    {
        $token = $this->ownedLocks[$lockKey] ?? null;
        unset($this->ownedLocks[$lockKey]);

        if ($token === null) {
            return;
        }

        $this->client->deleteIfValue($this->prefix . $lockKey, $token);
    }

    public function isLocked(string $lockKey): bool
    {
        return $this->client->exists($this->prefix . $lockKey);
    }
}
