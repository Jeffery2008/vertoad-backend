<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\RedisSocketConnectionInterface;
use VertoAD\Infrastructure\Redis\SocketRedisClient;

final class SocketRedisClientTest extends TestCase
{
    public function testConstructorRequiresPassword(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIS_PASSWORD is required for Redis connections.');

        new SocketRedisClient('127.0.0.1', 6379, '', 0, connection: new FakeRedisSocketConnection([]));
    }

    public function testAuthenticatesSelectsAndRunsRedisCommands(): void
    {
        $connection = new FakeRedisSocketConnection([
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "$5\r\nvalue\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":3\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "*2\r\n",
            "$7\r\nevent-a\r\n",
            ":9\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            ":1\r\n",
            "+OK\r\n",
            "+OK\r\n",
            "*2\r\n",
            "$8\r\nleased-a\r\n",
            ":8\r\n",
        ]);

        $client = new SocketRedisClient('127.0.0.1', 6379, 'secret', 2, connection: $connection);

        self::assertTrue($client->exists('key'));
        self::assertSame(1, $client->delete('key'));
        self::assertTrue($client->expire('key', 60));
        self::assertSame('value', $client->get('key'));
        self::assertSame(3, $client->increment('key'));
        self::assertTrue($client->setNxEx('key', 'value', 10));
        self::assertTrue($client->deleteIfValue('key', 'value'));
        self::assertSame(['event-a', '9'], $client->zRangeByScore('z', '-inf', '+inf', 1, 2));
        self::assertSame(1, $client->zAdd('z', 1.5, 'member'));
        self::assertSame(1, $client->zRem('z', 'member'));
        self::assertSame(['leased-a', '8'], $client->eval('return {}', ['k1', 'k2'], ['a1']));

        self::assertSame([
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['EXISTS', ['key']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['DEL', ['key']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['EXPIRE', ['key', '60']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['GET', ['key']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['INCR', ['key']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['SET', ['key', 'value', 'EX', '10', 'NX']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['EVAL', [self::deleteIfValueLua(), '1', 'key', 'value']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['ZRANGEBYSCORE', ['z', '-inf', '+inf', 'LIMIT', '1', '2']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['ZADD', ['z', '1.5', 'member']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['ZREM', ['z', 'member']],
            ['AUTH', ['secret']],
            ['SELECT', ['2']],
            ['EVAL', ['return {}', '2', 'k1', 'k2', 'a1']],
        ], $connection->commands);
    }

    public function testNormalizesMissingValuesAndFalseyCommandResults(): void
    {
        $connection = new FakeRedisSocketConnection([
            "+OK\r\n",
            ":0\r\n",
            "+OK\r\n",
            ":0\r\n",
            "+OK\r\n",
            "$-1\r\n",
            "+OK\r\n",
            ":0\r\n",
            "+OK\r\n",
            ":0\r\n",
            "+OK\r\n",
            "*-1\r\n",
            "+OK\r\n",
            "-ERR denied\r\n",
        ]);
        $client = new SocketRedisClient('127.0.0.1', 6379, 'secret', 0, connection: $connection);

        self::assertFalse($client->exists('missing'));
        self::assertFalse($client->expire('missing', 60));
        self::assertFalse($client->get('missing'));
        self::assertFalse($client->setNxEx('key', 'value', 10));
        self::assertFalse($client->deleteIfValue('key', 'value'));
        self::assertSame([], $client->zRangeByScore('z', '-inf', '+inf', 0, 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis command failed: ERR denied');
        $client->increment('denied');
    }

    public function testRejectsMalformedAndClosedResponses(): void
    {
        $closed = new FakeRedisSocketConnection(["+OK\r\n", '']);
        $client = new SocketRedisClient('127.0.0.1', 6379, 'secret', 0, connection: $closed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis server closed the connection.');
        $client->exists('key');
    }

    public function testRejectsUnsupportedResponses(): void
    {
        $connection = new FakeRedisSocketConnection(["+OK\r\n", "~1\r\n"]);
        $client = new SocketRedisClient('127.0.0.1', 6379, 'secret', 0, connection: $connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis returned an unsupported response type.');
        $client->exists('key');
    }

    private static function deleteIfValueLua(): string
    {
        return <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
    }
}

final class FakeRedisSocketConnection implements RedisSocketConnectionInterface
{
    /** @var list<array{0: string, 1: list<string>}> */
    public array $commands = [];

    /** @param list<string> $responses */
    public function __construct(private array $responses)
    {
    }

    public function write(string $payload): void
    {
        $parts = preg_split("/\r\n/", trim($payload));
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid Redis test payload.');
        }
        $index = 0;
        while ($index < count($parts) && $parts[$index] !== '') {
            $count = (int) substr((string) $parts[$index], 1);
            $command = (string) $parts[$index + 2];
            $arguments = [];
            for ($i = 1; $i < $count; $i++) {
                $arguments[] = (string) $parts[$index + $i * 2 + 2];
            }
            $this->commands[] = [$command, $arguments];
            $index += 1 + $count * 2;
        }
    }

    public function readLine(): string
    {
        $response = array_shift($this->responses);
        if ($response === null) {
            return '';
        }
        if ($response !== '' && $response[0] === '$' && !str_starts_with($response, '$-1')) {
            [$line, $body] = explode("\r\n", $response, 2);
            array_unshift($this->responses, $body);

            return $line . "\r\n";
        }

        return $response;
    }

    public function readBytes(int $bytes): string
    {
        $response = array_shift($this->responses) ?? '';

        return substr($response, 0, $bytes);
    }
}
