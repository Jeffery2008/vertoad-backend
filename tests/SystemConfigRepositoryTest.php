<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
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
}
