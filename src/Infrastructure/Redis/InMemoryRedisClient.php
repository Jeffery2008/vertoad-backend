<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

final class InMemoryRedisClient implements RedisClientInterface
{
    /** @var array<string, string> */
    private array $values = [];

    public function exists(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function delete(string $key): int
    {
        if (!isset($this->values[$key])) {
            return 0;
        }

        unset($this->values[$key]);

        return 1;
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        if (($this->values[$key] ?? null) !== $expectedValue) {
            return false;
        }

        unset($this->values[$key]);

        return true;
    }

    public function expire(string $key, int $seconds): bool
    {
        return isset($this->values[$key]) && $seconds > 0;
    }

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function increment(string $key): int
    {
        $this->values[$key] = (string) (((int) ($this->values[$key] ?? '0')) + 1);

        return (int) $this->values[$key];
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
        if (isset($this->values[$key]) || $seconds <= 0) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
    {
        return [];
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return 1;
    }

    public function zRem(string $key, string $member): int
    {
        return 1;
    }

    public function eval(string $script, array $keys, array $arguments): array
    {
        if (str_contains($script, 'SETEX') && count($keys) === 1 && count($arguments) >= 2) {
            $this->values[$keys[0]] = $arguments[1];

            return ['OK'];
        }

        return [];
    }
}
