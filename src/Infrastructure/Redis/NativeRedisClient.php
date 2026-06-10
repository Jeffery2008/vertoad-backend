<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

final readonly class NativeRedisClient implements RedisClientInterface
{
    public function __construct(private \Redis $redis)
    {
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function delete(string $key): int
    {
        return (int) $this->redis->del($key);
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        $deleted = $this->redis->eval(
            <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA,
            [$key, $expectedValue],
            1,
        );

        return (int) $deleted === 1;
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->redis->expire($key, $seconds);
    }

    public function get(string $key): string|false
    {
        $value = $this->redis->get($key);

        return is_string($value) ? $value : false;
    }

    public function increment(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
        return (bool) $this->redis->set($key, $value, ['nx', 'ex' => $seconds]);
    }

    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
    {
        $members = $this->redis->zRangeByScore($key, $from, $to, ['limit' => [$offset, $count]]);

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return (int) $this->redis->zAdd($key, $score, $member);
    }

    public function zRem(string $key, string $member): int
    {
        return (int) $this->redis->zRem($key, $member);
    }

    public function eval(string $script, array $keys, array $arguments): array
    {
        $members = $this->redis->eval($script, [...$keys, ...$arguments], count($keys));

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }
}
