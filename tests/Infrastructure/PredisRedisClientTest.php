<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\PredisCommandClientInterface;
use VertoAD\Infrastructure\Redis\PredisRedisClient;

final class PredisRedisClientTest extends TestCase
{
    public function testConstructorDoesNotOpenRedisConnection(): void
    {
        $commands = new RecordingPredisCommandClient();

        new PredisRedisClient($commands, 'secret', 2);

        self::assertSame(0, $commands->pipelineCalls);
        self::assertSame([], $commands->pipelineCommands);
    }

    public function testSkipsPipelineWhenAuthAndDatabaseAreNotConfigured(): void
    {
        $commands = new RecordingPredisCommandClient();

        new PredisRedisClient($commands, '', 0);

        self::assertSame(0, $commands->pipelineCalls);
    }

    public function testRunsConfiguredCommandsThroughAuthenticatedPipeline(): void
    {
        $commands = new RecordingPredisCommandClient([
            'auth' => 'OK',
            'select' => 'OK',
            'get' => 'stored-value',
        ]);
        $client = new PredisRedisClient($commands, 'secret', 2);
        $commands->pipelineCommands = [];

        self::assertSame('stored-value', $client->get('key'));
        self::assertSame([
            ['auth', ['secret']],
            ['select', [2]],
            ['get', ['key']],
        ], $commands->pipelineCommands);
    }

    public function testWrapsScalarAndCollectionCommands(): void
    {
        $commands = new RecordingPredisCommandClient([
            'exists' => 1,
            'del' => 1,
            'expire' => 1,
            'get' => 42,
            'incr' => 3,
            'set' => 'OK',
            'setex' => 'OK',
            'zrangebyscore' => ['event-a', 9],
            'zadd' => 1,
            'zrem' => 1,
            'eval' => ['leased-a', 8],
        ]);
        $client = new PredisRedisClient($commands);

        self::assertTrue($client->exists('key'));
        self::assertSame(1, $client->delete('key'));
        self::assertTrue($client->expire('key', 60));
        self::assertSame('42', $client->get('key'));
        self::assertSame(3, $client->increment('key'));
        self::assertTrue($client->setNxEx('key', 'value', 10));
        self::assertTrue($client->setEx('key', 'value', 10));
        self::assertTrue($client->deleteIfValue('key', 'value'));
        self::assertSame(['event-a', '9'], $client->zRangeByScore('z', '-inf', '+inf', 1, 2));
        self::assertSame(1, $client->zAdd('z', 1.5, 'member'));
        self::assertSame(1, $client->zRem('z', 'member'));
        self::assertSame(['leased-a', '8'], $client->eval('return {}', ['k1', 'k2'], ['a1']));

        self::assertSame(['key'], $commands->calls[0][1]);
        self::assertSame(['key'], $commands->calls[1][1]);
        self::assertSame(['key', 60], $commands->calls[2][1]);
        self::assertSame(['key'], $commands->calls[3][1]);
        self::assertSame(['key'], $commands->calls[4][1]);
        self::assertSame(['key', 'value', 'EX', 10, 'NX'], $commands->calls[5][1]);
        self::assertSame(['key', 10, 'value'], $commands->calls[6][1]);
        self::assertStringContainsString("redis.call('GET', KEYS[1])", (string) $commands->calls[7][1][0]);
        self::assertSame(1, $commands->calls[7][1][1]);
        self::assertSame('key', $commands->calls[7][1][2]);
        self::assertSame('value', $commands->calls[7][1][3]);
        self::assertSame(['z', '-inf', '+inf', ['limit' => [1, 2]]], $commands->calls[8][1]);
        self::assertSame(['z', ['member' => 1.5]], $commands->calls[9][1]);
        self::assertSame(['z', ['member']], $commands->calls[10][1]);
        self::assertSame(['return {}', 2, 'k1', 'k2', 'a1'], $commands->calls[11][1]);
    }

    public function testNormalizesMissingValuesAndFailedSet(): void
    {
        $commands = new RecordingPredisCommandClient([
            'exists' => 0,
            'expire' => 0,
            'get' => null,
            'set' => null,
            'setex' => null,
            'zrangebyscore' => 'not-list',
            'eval' => 'not-list',
        ]);
        $client = new PredisRedisClient($commands);

        self::assertFalse($client->exists('key'));
        self::assertFalse($client->expire('key', 60));
        self::assertFalse($client->get('key'));
        self::assertFalse($client->setNxEx('key', 'value', 10));
        self::assertFalse($client->setEx('key', 'value', 10));
        self::assertFalse($client->deleteIfValue('key', 'value'));
        self::assertSame([], $client->zRangeByScore('z', '-inf', '+inf', 0, 1));
        self::assertSame([], $client->eval('return {}', [], []));
    }

    public function testAuthenticatedPipelineNormalizesEmptyPipelineResponse(): void
    {
        $commands = new EmptyPipelinePredisCommandClient();
        $client = new PredisRedisClient($commands, 'secret', 0);

        self::assertFalse($client->get('key'));
    }
}

final class RecordingPredisCommandClient implements PredisCommandClientInterface
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $pipelineCommands = [];
    public int $pipelineCalls = 0;

    /** @param array<string, mixed> $responses */
    /** @param array<string, mixed> $responses */
    public function __construct(public array $responses = [])
    {
    }

    public function pipeline(callable $callback): mixed
    {
        $this->pipelineCalls++;
        $pipe = new class ($this) {
            /** @var list<mixed> */
            public array $responses = [];

            public function __construct(private RecordingPredisCommandClient $commands)
            {
            }

            public function __call(string $command, array $arguments): mixed
            {
                $this->commands->pipelineCommands[] = [$command, $arguments];
                $response = $this->commands->responses[$command] ?? null;
                $this->responses[] = $response;

                return $response;
            }
        };

        $callback($pipe);

        return $pipe->responses;
    }

    public function __call(string $command, array $arguments): mixed
    {
        $this->calls[] = [$command, $arguments];

        return $this->responses[$command] ?? null;
    }
}

final class EmptyPipelinePredisCommandClient implements PredisCommandClientInterface
{
    public function pipeline(callable $callback): mixed
    {
        $callback(new class {
            public function __call(string $command, array $arguments): mixed
            {
                return null;
            }
        });

        return [];
    }

    public function __call(string $command, array $arguments): mixed
    {
        return null;
    }
}
