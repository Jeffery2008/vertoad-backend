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
        $assetPolicy = [
            'upload_intent_ttl_seconds' => 900,
            'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
            'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
            'types' => [
                'image' => [
                    'max_bytes' => 10_485_760,
                    'max_width' => 4096,
                    'max_height' => 4096,
                    'allowed_content_types' => ['png' => 'image/png'],
                    'magic_signatures' => [
                        'image/png' => [['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")]],
                    ],
                ],
                'video' => [
                    'max_bytes' => 209_715_200,
                    'max_width' => 3840,
                    'max_height' => 2160,
                    'max_duration_seconds' => 120.0,
                    'allowed_content_types' => ['mp4' => 'video/mp4'],
                    'magic_signatures' => [
                        'video/mp4' => [['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']]],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [['trimmed_prefix_ascii' => '{']],
                    ],
                ],
                'text' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [['forbid_ascii_ci' => '<script']],
                    ],
                ],
            ],
        ];
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
                'review.ai_policy' => [
                    'enabled' => true,
                    'provider' => 'openai_compatible',
                    'base_url' => 'https://api.openai.example/v1',
                    'model' => 'review-model',
                    'prompt' => 'Return strict JSON.',
                    'timeout_seconds' => 60,
                    'max_input_tokens' => 12000,
                    'max_output_tokens' => 2000,
                    'temperature' => 0.2,
                ],
                'assets.upload_policy' => $assetPolicy,
            ]),
            $redis,
            'vertoad:test:',
            3600,
        );

        $result = $job->run();

        self::assertSame('config-cache-refresh', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(6, $result->metrics['refreshed'] ?? null);
        self::assertSame([
            ['vertoad:test:config:billing.default_revenue_share', '{"publisher_percent":70}', 3600],
            ['vertoad:test:config:attribution.default_window_seconds', '{"seconds":604800}', 3600],
            ['vertoad:test:config:security.rate_limit', '{"limit":60,"window_seconds":60}', 3600],
            ['vertoad:test:config:serving.event_validation', '{"min_visible_ratio":0.5,"min_visible_ms":1000,"repeat_click_window_seconds":30}', 3600],
            ['vertoad:test:config:review.ai_policy', '{"enabled":true,"provider":"openai_compatible","base_url":"https:\/\/api.openai.example\/v1","model":"review-model","prompt":"Return strict JSON.","timeout_seconds":60,"max_input_tokens":12000,"max_output_tokens":2000,"temperature":0.2}', 3600],
            ['vertoad:test:config:assets.upload_policy', json_encode($assetPolicy, JSON_THROW_ON_ERROR), 3600],
        ], $redis->setExCalls);
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
    /** @var list<array{0: string, 1: string, 2: int}> */
    public array $setExCalls = [];

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

    public function setEx(string $key, string $value, int $seconds): bool
    {
        $this->setExCalls[] = [$key, $value, $seconds];

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
