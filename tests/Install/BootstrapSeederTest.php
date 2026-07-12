<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Install\BootstrapConfigCatalog;
use VertoAD\Install\BootstrapSeeder;
use VertoAD\Install\InstallInput;
use VertoAD\Service\PermissionInventory;

final class BootstrapSeederTest extends TestCase
{
    public function testSeedsCompleteBootstrapDataInOneTransaction(): void
    {
        $connection = $this->connection();
        $result = (new BootstrapSeeder())->seed(
            $connection,
            InstallInput::fromArray(InstallTestInput::valid(), false),
            $this->secrets(),
            '198.51.100.10',
            'VertoAD Installer Test/1.0',
            'install-request-1',
        );
        $permissionCount = count((new PermissionInventory())->all());

        self::assertSame([
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'admin_user_id' => 1,
            'organization_id' => 1,
            'oauth_client_id' => 'voc_initial_test_client',
        ], $result);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM organizations'));
        self::assertSame($permissionCount, (int) $connection->fetchOne('SELECT COUNT(*) FROM permissions'));
        self::assertSame($permissionCount, (int) $connection->fetchOne('SELECT COUNT(*) FROM role_permissions'));
        self::assertSame($permissionCount, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_scopes'));
        self::assertSame($permissionCount, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_client_scopes'));
        self::assertSame(count(BootstrapConfigCatalog::all()), (int) $connection->fetchOne('SELECT COUNT(*) FROM system_config_versions'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM revenue_share_rules WHERE scope = 'global' AND share_ratio_bps = 7000"));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_installations'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'installation.completed'"));

        $user = $connection->fetchAssociative('SELECT * FROM users');
        self::assertIsArray($user);
        self::assertTrue(password_verify('Correct-Horse-2026!', (string) $user['password_hash']));
        self::assertSame('owner@example.com', $user['email']);
        self::assertNotNull($user['email_verified_at']);
        $client = $connection->fetchAssociative('SELECT * FROM oauth_clients');
        self::assertIsArray($client);
        self::assertNull($client['secret_hash']);
        self::assertSame('VertoAD First-Party SPA', $client['name']);
        self::assertSame(0, (int) $client['is_confidential']);
        self::assertSame(['authorization_code', 'refresh_token'], json_decode((string) $client['grant_types_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertCount($permissionCount, json_decode((string) $client['scopes_json'], true, flags: JSON_THROW_ON_ERROR));

        $geo = $connection->fetchOne("SELECT value_json FROM system_config_versions WHERE config_key = 'serving.geo_provider'");
        self::assertIsString($geo);
        self::assertTrue(json_decode($geo, true, flags: JSON_THROW_ON_ERROR)['include_builtins']);
        $installation = $connection->fetchAssociative('SELECT * FROM app_installations');
        self::assertIsArray($installation);
        self::assertSame('C633640A', strtoupper(bin2hex((string) $installation['installed_by_ip'])));
        self::assertSame('install-request-1', $installation['request_id']);
        self::assertSame($permissionCount, json_decode((string) $installation['metadata_json'], true, flags: JSON_THROW_ON_ERROR)['permission_count']);
        self::assertSame('VertoAD Installer Test/1.0', $connection->fetchOne("SELECT user_agent FROM audit_logs WHERE action = 'installation.completed'"));
        $connection->close();
    }

    public function testInvalidClientIpIsStoredAsNull(): void
    {
        $connection = $this->connection();
        (new BootstrapSeeder())->seed(
            $connection,
            InstallInput::fromArray(InstallTestInput::valid(), false),
            $this->secrets(),
            'invalid-ip',
            null,
            'install-request-invalid-ip',
        );

        self::assertNull($connection->fetchOne('SELECT installed_by_ip FROM app_installations'));
        $connection->close();
    }

    public function testRejectsNonEmptyMigratedDatabaseBeforeWritingAnything(): void
    {
        $connection = $this->connection();
        $connection->insert('organizations', ['name' => 'Existing', 'slug' => 'existing', 'billing_status' => 'active']);

        try {
            (new BootstrapSeeder())->seed(
                $connection,
                InstallInput::fromArray(InstallTestInput::valid(), false),
                $this->secrets(),
                null,
                null,
                'install-request-existing',
            );
            self::fail('Expected non-empty database to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installation requires an empty migrated database.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM organizations'));
        $connection->close();
    }

    public function testRollsBackAllBootstrapRowsWhenLateSeedStepFails(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE oauth_scopes');

        try {
            (new BootstrapSeeder())->seed(
                $connection,
                InstallInput::fromArray(InstallTestInput::valid(), false),
                $this->secrets(),
                null,
                null,
                'install-request-rollback',
            );
            self::fail('Expected late seed failure.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('oauth_scopes', $exception->getMessage());
        }

        foreach (['users', 'organizations', 'roles', 'permissions', 'oauth_clients'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table), $table);
        }
        $connection->close();
    }

    public function testRejectsMissingAutoIncrementIdentifier(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('lastInsertId')->willReturn('0');
        $method = new \ReflectionMethod(BootstrapSeeder::class, 'lastInsertId');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to persist bootstrap probe.');
        $method->invoke(new BootstrapSeeder(), $connection, 'probe');
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        SqliteBootstrapSchema::create($connection);

        return $connection;
    }

    /** @return array{installation_id: string, app_key: string, oauth_encryption_key: string, oauth_client_id: string, cron_api_token: string, webhook_signing_secret: string} */
    private function secrets(): array
    {
        return [
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'app_key' => 'not-used-by-seeder',
            'oauth_encryption_key' => 'not-used-by-seeder',
            'oauth_client_id' => 'voc_initial_test_client',
            'cron_api_token' => 'not-used-by-seeder',
            'webhook_signing_secret' => 'not-used-by-seeder',
        ];
    }
}
