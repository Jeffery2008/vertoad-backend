<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Tests\Operations\DatabaseConfigVersionRepositoryTest;
use VertoAD\Repository\SystemConfigRepository;

final class SystemConfigRepositoryTest extends TestCase
{
    public function testFindLatestValueReturnsHighestVersionConfig(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE system_config_versions (config_key VARCHAR(160), version INTEGER, value_json TEXT)'
        );
        $connection->insert('system_config_versions', [
            'config_key' => 'billing.default_revenue_share',
            'version' => 1,
            'value_json' => '{"publisher_percent":60}',
        ]);
        $connection->insert('system_config_versions', [
            'config_key' => 'billing.default_revenue_share',
            'version' => 2,
            'value_json' => '{"publisher_percent":70}',
        ]);

        $repository = new SystemConfigRepository($connection);

        self::assertSame(
            ['publisher_percent' => 70],
            $repository->findLatestValue('billing.default_revenue_share')
        );
    }

    public function testFindLatestValueReturnsNullWhenMissing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE system_config_versions (config_key VARCHAR(160), version INTEGER, value_json TEXT)'
        );

        $repository = new SystemConfigRepository($connection);

        self::assertNull($repository->findLatestValue('missing'));
    }

    public function testListLatestValuesReturnsHighestVersionForEachConfigKey(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE system_config_versions (config_key VARCHAR(160), version INTEGER, value_json TEXT)'
        );
        $connection->insert('system_config_versions', [
            'config_key' => 'billing.default_revenue_share',
            'version' => 1,
            'value_json' => '{"publisher_percent":60}',
        ]);
        $connection->insert('system_config_versions', [
            'config_key' => 'billing.default_revenue_share',
            'version' => 2,
            'value_json' => '{"publisher_percent":70}',
        ]);
        $connection->insert('system_config_versions', [
            'config_key' => 'security.rate_limit',
            'version' => 1,
            'value_json' => '{"limit":60}',
        ]);

        $repository = new SystemConfigRepository($connection);

        self::assertSame([
            'billing.default_revenue_share' => ['publisher_percent' => 70],
            'security.rate_limit' => ['limit' => 60],
        ], $repository->listLatestValues());
    }

    public function testReadsLatestBusinessConfigValuesFromOperationsSchema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DatabaseConfigVersionRepositoryTest::createSchema($connection);
        $this->insertVersion($connection, 'attribution.default_window_seconds', 1, ['seconds' => 3600]);
        $this->insertVersion($connection, 'attribution.default_window_seconds', 2, ['seconds' => 7200]);
        $this->insertVersion($connection, 'security.rate_limit', 1, ['limit' => 60, 'window_seconds' => 60]);
        $this->insertVersion($connection, 'serving.event_validation', 1, [
            'min_visible_ratio' => 0.5,
            'min_visible_ms' => 1000,
            'repeat_click_window_seconds' => 30,
        ]);
        $assetUploadPolicy = [
            'upload_intent_ttl_seconds' => 900,
            'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
            'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
            'types' => [
                'image' => [
                    'max_bytes' => 10_485_760,
                    'max_width' => 4096,
                    'max_height' => 4096,
                    'allowed_content_types' => ['png' => 'image/png'],
                    'magic_signatures' => ['image/png' => [['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")]]],
                ],
                'video' => [
                    'max_bytes' => 209_715_200,
                    'max_width' => 3840,
                    'max_height' => 2160,
                    'max_duration_seconds' => 120,
                    'allowed_content_types' => ['mp4' => 'video/mp4'],
                    'magic_signatures' => ['video/mp4' => [['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']]]],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => ['application/json' => [['trimmed_prefix_ascii' => '{']]],
                ],
                'text' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => ['text/plain' => [['forbid_ascii_ci' => '<script']]],
                ],
            ],
        ];
        $this->insertVersion($connection, 'assets.upload_policy', 1, $assetUploadPolicy);
        $this->insertVersion($connection, 'webhook.delivery_policy', 1, [
            'batch_size' => 50,
            'http_timeout_seconds' => 5,
            'max_retry_count' => 3,
            'retry_base_backoff_seconds' => 300,
        ]);

        $repository = new SystemConfigRepository($connection);

        self::assertSame(
            ['seconds' => 7200],
            $repository->findLatestValue('attribution.default_window_seconds')
        );
        self::assertSame([
            'assets.upload_policy' => $assetUploadPolicy,
            'attribution.default_window_seconds' => ['seconds' => 7200],
            'security.rate_limit' => ['limit' => 60, 'window_seconds' => 60],
            'serving.event_validation' => [
                'min_visible_ratio' => 0.5,
                'min_visible_ms' => 1000,
                'repeat_click_window_seconds' => 30,
            ],
            'webhook.delivery_policy' => [
                'batch_size' => 50,
                'http_timeout_seconds' => 5,
                'max_retry_count' => 3,
                'retry_base_backoff_seconds' => 300,
            ],
        ], $repository->listLatestValues());
    }

    /**
     * @param array<string, mixed> $value
     */
    private function insertVersion(\Doctrine\DBAL\Connection $connection, string $key, int $version, array $value): void
    {
        $connection->insert('system_config_versions', [
            'version_id' => 'cfgv_' . sha1($key . ':' . $version),
            'config_key' => $key,
            'version' => $version,
            'value_json' => json_encode($value, JSON_THROW_ON_ERROR),
            'created_by_user_id' => 1,
            'created_at' => '2026-06-12 00:00:00',
        ]);
    }
}
