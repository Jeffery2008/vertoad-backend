<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\Cron\ConfigCacheRefreshJob;

final class ConfigCacheRefreshJobTest extends TestCase
{
    public function testRefreshesLatestBusinessConfigValuesIntoRedisCache(): void
    {
        $redis = new RecordingRedisClient();
        $job = new ConfigCacheRefreshJob(
            new StaticSystemConfigRepository([
                'billing.default_revenue_share' => ['publisher_percent' => 70],
                'attribution.default_window_seconds' => ['seconds' => 604800],
                'security.rate_limit' => ['limit' => 60, 'window_seconds' => 60],
                'serving.event_validation' => [
                    'min_visible_ratio' => 0.5,
                    'min_visible_ms' => 1000,
                    'repeat_click_window_seconds' => 30,
                ],
            ]),
            $redis,
            'vertoad:test:',
            3600,
        );

        $result = $job->run();

        self::assertSame('config-cache-refresh', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(4, $result->metrics['refreshed'] ?? null);
        self::assertSame([
            [
                "return {redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])}",
                ['vertoad:test:config:billing.default_revenue_share'],
                ['3600', '{"publisher_percent":70}'],
            ],
            [
                "return {redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])}",
                ['vertoad:test:config:attribution.default_window_seconds'],
                ['3600', '{"seconds":604800}'],
            ],
            [
                "return {redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])}",
                ['vertoad:test:config:security.rate_limit'],
                ['3600', '{"limit":60,"window_seconds":60}'],
            ],
            [
                "return {redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])}",
                ['vertoad:test:config:serving.event_validation'],
                ['3600', '{"min_visible_ratio":0.5,"min_visible_ms":1000,"repeat_click_window_seconds":30}'],
            ],
        ], $redis->evalCalls);
    }

    public function testRejectsInvalidTtlAndCachePrefix(): void
    {
        $repository = new StaticSystemConfigRepository([]);
        $redis = new RecordingRedisClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config cache TTL must be positive.');
        new ConfigCacheRefreshJob($repository, $redis, 'vertoad:test:', 0);
    }

    public function testRejectsBlankCachePrefix(): void
    {
        $repository = new StaticSystemConfigRepository([]);
        $redis = new RecordingRedisClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config cache prefix is required.');
        new ConfigCacheRefreshJob($repository, $redis, '', 60);
    }
}

final readonly class StaticSystemConfigRepository implements SystemConfigRepositoryInterface
{
    /** @param array<string, array<string, mixed>> $values */
    public function __construct(private array $values)
    {
    }

    public function findLatestValue(string $configKey): ?array
    {
        return $this->values[$configKey] ?? null;
    }

    public function listLatestValues(): array
    {
        return $this->values;
    }
}

final class RecordingRedisClient implements RedisClientInterface
{
    /** @var list<array{0: string, 1: list<string>, 2: list<string>}> */
    public array $evalCalls = [];

    public function exists(string $key): bool
    {
        return false;
    }

    public function delete(string $key): int
    {
        return 1;
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        return true;
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function get(string $key): string|false
    {
        return false;
    }

    public function increment(string $key): int
    {
        return 1;
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
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
        $this->evalCalls[] = [$script, $keys, $arguments];

        return [];
    }
}
