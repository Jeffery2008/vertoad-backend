<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Service\Serving\RedisServingFrequencyCapStore;

final class RedisServingFrequencyCapStoreTest extends TestCase
{
    public function testRecordsServeCountsInHourAndDayBucketsWithPrefixAndTtl(): void
    {
        $redis = new RecordingRedisClient();
        $store = new RedisServingFrequencyCapStore($redis, 'vertoad:test:', 60);
        $at = new DateTimeImmutable('2026-06-08T10:15:00+00:00');

        self::assertSame(0, $store->servedCount(30, 20, 'viewer-1', 'hour', $at));

        $store->recordServe(30, 20, 'viewer-1', $at);
        $store->recordServe(30, 20, 'viewer-1', $at->modify('+5 minutes'));

        self::assertSame(2, $store->servedCount(30, 20, 'viewer-1', 'hour', $at));
        self::assertSame(2, $store->servedCount(30, 20, 'viewer-1', 'day', $at));
        self::assertSame(0, $store->servedCount(30, 20, 'viewer-1', 'hour', $at->modify('+1 hour')));
        self::assertCount(2, $redis->values);
        self::assertContains(2760, $redis->expirations);
        self::assertContains(49560, $redis->expirations);
        foreach (array_keys($redis->values) as $key) {
            self::assertStringStartsWith('vertoad:test:serving:frequency:', $key);
            self::assertStringNotContainsString('viewer-1', $key);
        }
    }

    public function testRejectsUnsupportedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serving frequency cap window is not supported.');

        (new RedisServingFrequencyCapStore(new RecordingRedisClient(), 'vertoad:test:'))
            ->servedCount(30, 20, 'viewer-1', 'week', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
    }
}

final class RecordingRedisClient implements RedisClientInterface
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var list<int> */
    public array $expirations = [];

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function delete(string $key): int
    {
        if (!$this->exists($key)) {
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
        $this->expirations[] = $seconds;

        return $this->exists($key);
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
        if ($this->exists($key) || $seconds <= 0) {
            return false;
        }

        $this->values[$key] = $value;
        $this->expirations[] = $seconds;

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
        return [];
    }
}
