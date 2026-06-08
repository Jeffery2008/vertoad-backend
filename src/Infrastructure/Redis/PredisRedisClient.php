<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

final readonly class PredisRedisClient implements RedisClientInterface
{
    public function __construct(
        private PredisCommandClientInterface $client,
        private ?string $password = null,
        private int $database = 0,
    )
    {
    }

    public function exists(string $key): bool
    {
        return (int) $this->runAuthenticated(fn (object $client): mixed => $client->exists($key)) > 0;
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->runAuthenticated(fn (object $client): mixed => $client->expire($key, $seconds));
    }

    public function get(string $key): string|false
    {
        $value = $this->runAuthenticated(fn (object $client): mixed => $client->get($key));

        return is_scalar($value) ? (string) $value : false;
    }

    public function increment(string $key): int
    {
        return (int) $this->runAuthenticated(fn (object $client): mixed => $client->incr($key));
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
        return (string) $this->runAuthenticated(
            fn (object $client): mixed => $client->set($key, $value, 'EX', $seconds, 'NX'),
        ) === 'OK';
    }

    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
    {
        $members = $this->runAuthenticated(
            fn (object $client): mixed => $client->zrangebyscore($key, $from, $to, ['limit' => [$offset, $count]]),
        );

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return (int) $this->runAuthenticated(fn (object $client): mixed => $client->zadd($key, [$member => $score]));
    }

    public function zRem(string $key, string $member): int
    {
        return (int) $this->runAuthenticated(fn (object $client): mixed => $client->zrem($key, [$member]));
    }

    public function eval(string $script, array $keys, array $arguments): array
    {
        $members = $this->runAuthenticated(
            fn (object $client): mixed => $client->eval($script, count($keys), ...$keys, ...$arguments),
        );

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }

    /**
     * @param callable(object): mixed $command
     */
    private function runAuthenticated(callable $command): mixed
    {
        if (($this->password === null || $this->password === '') && $this->database <= 0) {
            return $command($this->client);
        }

        $responses = $this->client->pipeline(function (object $pipe) use ($command): void {
            if ($this->password !== null && $this->password !== '') {
                $pipe->auth($this->password);
            }
            if ($this->database > 0) {
                $pipe->select($this->database);
            }
            $command($pipe);
        });

        if (!is_array($responses) || $responses === []) {
            return null;
        }

        return $responses[array_key_last($responses)];
    }
}
