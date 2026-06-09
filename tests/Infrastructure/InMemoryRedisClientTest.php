<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\InMemoryRedisClient;

final class InMemoryRedisClientTest extends TestCase
{
    public function testSupportsScalarCommandsAndSetNxSemantics(): void
    {
        $client = new InMemoryRedisClient();

        self::assertFalse($client->exists('key'));
        self::assertFalse($client->get('key'));
        self::assertFalse($client->expire('key', 60));
        self::assertTrue($client->setNxEx('key', 'value', 60));
        self::assertFalse($client->setNxEx('key', 'other', 60));
        self::assertTrue($client->exists('key'));
        self::assertTrue($client->expire('key', 60));
        self::assertSame('value', $client->get('key'));
        self::assertSame(0, $client->delete('missing'));
        self::assertSame(1, $client->delete('key'));
        self::assertFalse($client->exists('key'));
        self::assertSame(1, $client->increment('count'));
        self::assertSame(2, $client->increment('count'));
    }

    public function testSupportsConfigSetexEvalAndNoopSortedSetCommands(): void
    {
        $client = new InMemoryRedisClient();

        self::assertSame(
            ['OK'],
            $client->eval("return {redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])}", ['config:key'], ['60', '{"enabled":true}']),
        );
        self::assertSame('{"enabled":true}', $client->get('config:key'));
        self::assertSame([], $client->eval('return {}', [], []));
        self::assertSame([], $client->zRangeByScore('z', '-inf', '+inf', 0, 10));
        self::assertSame(1, $client->zAdd('z', 1.5, 'member'));
        self::assertSame(1, $client->zRem('z', 'member'));
        self::assertFalse($client->setNxEx('expired', 'value', 0));
    }
}
