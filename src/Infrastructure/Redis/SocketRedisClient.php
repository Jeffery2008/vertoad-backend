<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

final class SocketRedisClient implements RedisClientInterface
{
    private RedisSocketConnectionInterface $connection;

    public function __construct(
        string $host,
        int $port,
        private readonly string $password,
        private readonly int $database = 0,
        float $timeoutSeconds = 2.0,
        float $readTimeoutSeconds = 2.0,
        ?RedisSocketConnectionInterface $connection = null,
    ) {
        if ($password === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for Redis connections.');
        }

        $this->connection = $connection ?? self::openConnection($host, $port, $timeoutSeconds, $readTimeoutSeconds);
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            (string) ($settings['host'] ?? '127.0.0.1'),
            (int) ($settings['port'] ?? 6379),
            (string) ($settings['password'] ?? ''),
            (int) ($settings['database'] ?? 0),
            (float) ($settings['timeout_seconds'] ?? 2.0),
            (float) ($settings['read_timeout_seconds'] ?? 2.0),
        );
    }

    public function exists(string $key): bool
    {
        return (int) $this->command('EXISTS', [$key]) > 0;
    }

    public function delete(string $key): int
    {
        return (int) $this->command('DEL', [$key]);
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        $deleted = $this->command(
            'EVAL',
            [
                <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA,
                '1',
                $key,
                $expectedValue,
            ],
        );

        return (int) $deleted === 1;
    }

    public function expire(string $key, int $seconds): bool
    {
        return (int) $this->command('EXPIRE', [$key, (string) $seconds]) === 1;
    }

    public function get(string $key): string|false
    {
        $value = $this->command('GET', [$key]);

        return is_scalar($value) ? (string) $value : false;
    }

    public function increment(string $key): int
    {
        return (int) $this->command('INCR', [$key]);
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
        return $this->command('SET', [$key, $value, 'EX', (string) $seconds, 'NX']) === 'OK';
    }

    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
    {
        $members = $this->command('ZRANGEBYSCORE', [$key, $from, $to, 'LIMIT', (string) $offset, (string) $count]);

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        return (int) $this->command('ZADD', [$key, (string) $score, $member]);
    }

    public function zRem(string $key, string $member): int
    {
        return (int) $this->command('ZREM', [$key, $member]);
    }

    public function eval(string $script, array $keys, array $arguments): array
    {
        $members = $this->command('EVAL', [$script, (string) count($keys), ...$keys, ...$arguments]);

        return array_values(array_map('strval', is_array($members) ? $members : []));
    }

    /**
     * @param list<string> $arguments
     */
    private function command(string $command, array $arguments): mixed
    {
        $commandCount = 1;
        $payload = $this->serialize('AUTH', [$this->password]);
        if ($this->database > 0) {
            $payload .= $this->serialize('SELECT', [(string) $this->database]);
            $commandCount++;
        }
        $payload .= $this->serialize($command, $arguments);
        $commandCount++;

        $this->connection->write($payload);

        $response = null;
        for ($i = 0; $i < $commandCount; $i++) {
            $response = $this->readResponse();
        }

        return $response;
    }

    /**
     * @param list<string> $arguments
     */
    private function serialize(string $command, array $arguments): string
    {
        $parts = [$command, ...$arguments];
        $buffer = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $buffer .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $buffer;
    }

    private function readResponse(): mixed
    {
        $line = $this->connection->readLine();
        if ($line === '') {
            throw new \RuntimeException('Redis server closed the connection.');
        }

        $prefix = $line[0];
        $payload = substr($line, 1, -2);

        return match ($prefix) {
            '+' => $payload,
            '-' => throw new \RuntimeException('Redis command failed: ' . $payload),
            ':' => (int) $payload,
            '$' => $this->readBulkString((int) $payload),
            '*' => $this->readArray((int) $payload),
            default => throw new \RuntimeException('Redis returned an unsupported response type.'),
        };
    }

    private function readBulkString(int $bytes): string|null
    {
        if ($bytes < 0) {
            return null;
        }

        return substr($this->connection->readBytes($bytes + 2), 0, -2);
    }

    /**
     * @return list<mixed>|null
     */
    private function readArray(int $count): ?array
    {
        if ($count < 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readResponse();
        }

        return $items;
    }

    /**
     * @codeCoverageIgnore This wraps PHP stream resources; protocol behavior is covered with a fake connection.
     */
    private static function openConnection(string $host, int $port, float $timeoutSeconds, float $readTimeoutSeconds): RedisSocketConnectionInterface
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (!$socket) {
            throw new \RuntimeException(sprintf('Unable to connect to Redis at %s:%d: %s', $host, $port, $errstr));
        }

        stream_set_timeout($socket, (int) floor($readTimeoutSeconds), (int) (($readTimeoutSeconds - floor($readTimeoutSeconds)) * 1_000_000));

        return new ResourceRedisSocketConnection($socket);
    }
}
