<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

interface RedisClientInterface
{
    public function exists(string $key): bool;

    public function expire(string $key, int $seconds): bool;

    public function get(string $key): string|false;

    public function increment(string $key): int;

    public function setNxEx(string $key, string $value, int $seconds): bool;

    /**
     * @return list<string>
     */
    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array;

    public function zAdd(string $key, float $score, string $member): int;

    public function zRem(string $key, string $member): int;

    /**
     * @param list<string> $keys
     * @param list<string> $arguments
     * @return list<string>
     */
    public function eval(string $script, array $keys, array $arguments): array;
}
