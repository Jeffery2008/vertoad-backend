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

        $repository = new SystemConfigRepository($connection);

        self::assertSame(
            ['seconds' => 7200],
            $repository->findLatestValue('attribution.default_window_seconds')
        );
        self::assertSame([
            'attribution.default_window_seconds' => ['seconds' => 7200],
            'security.rate_limit' => ['limit' => 60, 'window_seconds' => 60],
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
