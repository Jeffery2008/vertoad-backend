<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Operations\ConfigVersion;
use VertoAD\Repository\Operations\DatabaseConfigVersionRepository;

final class DatabaseConfigVersionRepositoryTest extends TestCase
{
    public function testAppendsFindsAndListsConfigVersionsByKey(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createSchema($connection);
        $repository = new DatabaseConfigVersionRepository($connection);

        $first = new ConfigVersion(
            version_id: 'cfgv_first',
            config_key: 'security.rate_limit',
            version_number: 1,
            value: ['limit' => 60, 'window_seconds' => 60],
            created_by_user_id: 7,
            created_at: new \DateTimeImmutable('2026-06-09T08:00:00Z'),
        );
        $second = new ConfigVersion(
            version_id: 'cfgv_second',
            config_key: 'security.rate_limit',
            version_number: 2,
            value: ['limit' => 100, 'window_seconds' => 60],
            created_by_user_id: 9,
            created_at: new \DateTimeImmutable('2026-06-09T09:00:00Z'),
        );

        self::assertSame($first, $repository->append($first));
        self::assertSame($second, $repository->append($second));

        self::assertSame(['limit' => 60, 'window_seconds' => 60], $repository->find('cfgv_first')?->value);
        self::assertNull($repository->find('missing'));
        self::assertSame(3, $repository->nextVersionNumber('security.rate_limit'));
        self::assertSame(1, $repository->nextVersionNumber('webhooks.timeout'));
        self::assertSame(
            ['cfgv_first', 'cfgv_second'],
            array_map(static fn (ConfigVersion $version): string => $version->version_id, $repository->listByKey('security.rate_limit')),
        );
    }

    public function testHydratesNonObjectJsonAsEmptyValue(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createSchema($connection);
        $connection->insert('system_config_versions', [
            'version_id' => 'cfgv_scalar',
            'config_key' => 'security.rate_limit',
            'version' => 1,
            'value_json' => 'true',
            'created_by_user_id' => 7,
            'created_at' => '2026-06-09 08:00:00',
        ]);

        self::assertSame([], (new DatabaseConfigVersionRepository($connection))->find('cfgv_scalar')?->value);
    }

    public static function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE system_config_versions (
                version_id VARCHAR(80) PRIMARY KEY,
                config_key VARCHAR(160) NOT NULL,
                version INTEGER NOT NULL,
                value_json TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }
}
