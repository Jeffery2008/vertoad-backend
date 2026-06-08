<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

use VertoAD\Infrastructure\Redis\NativeRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final readonly class RedisRateLimitStore implements RateLimitStoreInterface
{
    private RedisClientInterface $client;

    public function __construct(RedisClientInterface|\Redis $redis, private string $prefix)
    {
        $this->client = $redis instanceof RedisClientInterface ? $redis : new NativeRedisClient($redis);
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(RedisClientFactory::fromSettings($settings), (string) ($settings['prefix'] ?? 'vertoad:'));
    }

    public function increment(string $key, int $windowStartsAt, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('Rate limit window must be at least 1 second.');
        }

        $redisKey = $this->prefix . 'rate-limit:' . hash('sha256', $key . ':' . $windowStartsAt);
        $count = $this->client->increment($redisKey);
        if ($count === 1) {
            $this->client->expire($redisKey, $windowSeconds);
        }

        return $count;
    }
}
