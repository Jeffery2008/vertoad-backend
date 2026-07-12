<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Install\BootstrapSeeder;
use VertoAD\Install\InstallFilesystem;
use VertoAD\Install\InstallHttpException;
use VertoAD\Install\InstallInput;
use VertoAD\Install\InstallerService;
use VertoAD\Install\InstallSecretGenerator;
use VertoAD\Install\InstallState;
use VertoAD\Install\MigrationRunnerInterface;

final class InstallerServiceTest extends TestCase
{
    private string|false $previousInstalled;
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $this->previousInstalled = getenv('APP_INSTALLED');
        putenv('APP_INSTALLED=false');
    }

    protected function tearDown(): void
    {
        $this->previousInstalled === false
            ? putenv('APP_INSTALLED')
            : putenv('APP_INSTALLED=' . $this->previousInstalled);
        foreach (array_reverse($this->temporaryDirectories) as $path) {
            $this->removeDirectory($path);
        }
    }

    public function testRunsMigrationSeedAndAtomicFinalizationEndToEnd(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . '/.env.example', "APP_ENV=local\nINSTALL_TOKEN=temporary\nCUSTOM_KEEP=yes\n");
        $databasePath = $root . '/bootstrap.sqlite';
        $migrations = $this->schemaMigration($databasePath);
        $service = $this->service($root, 'production', $migrations, $databasePath);

        $result = $service->install(
            InstallInput::fromArray(InstallTestInput::valid(), false),
            false,
            '203.0.113.9',
            'Installer Service Test/1.0',
            'install-service-success',
        );

        self::assertSame(1, $result['admin_user_id']);
        self::assertSame(1, $result['organization_id']);
        self::assertSame($result['oauth_client_id'], $migrations->settings === [] ? null : $result['oauth_client_id']);
        self::assertStringStartsWith('voc_', $result['oauth_client_id']);
        self::assertArrayNotHasKey('oauth_client_secret', $result);
        self::assertSame('pdo_mysql', $migrations->settings['driver']);
        self::assertSame('Db$Password-2026', $migrations->settings['password']);

        $environment = (string) file_get_contents($root . '/.env');
        self::assertStringContainsString('APP_ENV="production"', $environment);
        self::assertStringContainsString('APP_DEBUG=false', $environment);
        self::assertStringContainsString('APP_INSTALLED=true', $environment);
        self::assertStringContainsString('APP_INSTALLATION_ID="' . $result['installation_id'] . '"', $environment);
        self::assertStringContainsString('DB_PASSWORD="Db\\$Password-2026"', $environment);
        self::assertStringContainsString('PHINX_ENVIRONMENT="production"', $environment);
        self::assertStringContainsString('OAUTH_PRIVATE_KEY_PATH="storage/oauth/private.key"', $environment);
        self::assertStringContainsString('OAUTH_PUBLIC_KEY_PATH="storage/oauth/public.key"', $environment);
        self::assertStringNotContainsString(str_replace('\\', '/', $root) . '/storage/oauth', str_replace('\\', '/', $environment));
        self::assertStringContainsString('INSTALL_TOKEN=""', $environment);
        self::assertStringNotContainsString('OAUTH_PRIVATE_KEY_PASSPHRASE', $environment);
        self::assertStringContainsString('CUSTOM_KEEP=yes', $environment);
        self::assertStringNotContainsString('OAUTH_CLIENT_SECRET', $environment);
        self::assertFileExists($root . '/storage/install.lock');
        $lock = json_decode((string) file_get_contents($root . '/storage/install.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $lock['format_version']);
        self::assertSame('installed', $lock['state']);
        self::assertSame($result['installation_id'], $lock['installation_id']);
        self::assertNotFalse(openssl_pkey_get_private((string) file_get_contents($root . '/storage/oauth/private.key')));
        self::assertNotFalse(openssl_pkey_get_public((string) file_get_contents($root . '/storage/oauth/public.key')));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_installations'));
        $client = $connection->fetchAssociative('SELECT secret_hash, grant_types_json, is_confidential FROM oauth_clients');
        self::assertIsArray($client);
        self::assertNull($client['secret_hash']);
        self::assertSame(0, (int) $client['is_confidential']);
        self::assertSame(['authorization_code', 'refresh_token'], json_decode((string) $client['grant_types_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('install-service-success', $connection->fetchOne('SELECT request_id FROM app_installations'));
        self::assertSame('Installer Service Test/1.0', $connection->fetchOne("SELECT user_agent FROM audit_logs WHERE action = 'installation.completed'"));
        $connection->close();
    }

    public function testSeedFailureLeavesNoEnvironmentKeysOrPermanentLock(): void
    {
        $root = $this->temporaryDirectory();
        $databasePath = $root . '/failed-seed.sqlite';
        $migrations = $this->schemaMigration($databasePath, dropOauthScopes: true);
        $service = $this->service($root, '', $migrations, $databasePath);

        try {
            $service->install(
                InstallInput::fromArray(InstallTestInput::valid(), false),
                false,
                null,
                null,
                'install-service-failed-seed',
            );
            self::fail('Expected seed failure.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('oauth_scopes', $exception->getMessage());
        }

        self::assertFileDoesNotExist($root . '/storage/install.lock');
        self::assertFileDoesNotExist($root . '/storage/oauth/private.key');
        self::assertFileDoesNotExist($root . '/storage/oauth/public.key');
        self::assertFileDoesNotExist($root . '/.env');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        $connection->close();
    }

    public function testMigrationFailureDoesNotWriteEnvironmentOrPermanentLock(): void
    {
        $root = $this->temporaryDirectory();
        $migrations = new class implements MigrationRunnerInterface {
            public function migrate(array $databaseSettings): void
            {
                throw new \RuntimeException('migration failed');
            }
        };
        $service = new InstallerService(
            'testing',
            new InstallState($root),
            new InstallFilesystem($root),
            $migrations,
            new BootstrapSeeder(),
            new InstallSecretGenerator(2048),
            static fn (array $settings): Connection => throw new \LogicException('Connection must not be created.'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration failed');
        try {
            $service->install(InstallInput::fromArray(InstallTestInput::valid(), false), false, null, null, 'migration-failed');
        } finally {
            self::assertFileDoesNotExist($root . '/.env');
            self::assertFileDoesNotExist($root . '/storage/install.lock');
            self::assertFileDoesNotExist($root . '/storage/oauth/private.key');
            self::assertFileDoesNotExist($root . '/storage/oauth/public.key');
        }
    }

    public function testRejectsAlreadyInstalledStateBeforeGeneratingSecretsOrMigrating(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/storage', 0700, true);
        file_put_contents($root . '/storage/install.lock', '{}');
        $migrations = new class implements MigrationRunnerInterface {
            public bool $called = false;
            public function migrate(array $databaseSettings): void
            {
                $this->called = true;
            }
        };
        $service = new InstallerService(
            'testing',
            new InstallState($root),
            new InstallFilesystem($root),
            $migrations,
            new BootstrapSeeder(),
            new InstallSecretGenerator(2048),
            static fn (array $settings): Connection => throw new \LogicException('Connection must not be created.'),
        );

        try {
            $service->install(InstallInput::fromArray(InstallTestInput::valid(), false), false, null, null, 'already-installed');
            self::fail('Expected installed state to reject installer.');
        } catch (InstallHttpException $exception) {
            self::assertSame(410, $exception->statusCode);
            self::assertSame('already_installed', $exception->errorCode);
        }
        self::assertFalse($migrations->called);
    }

    public function testReportsDatabaseRollbackFailureWithoutMaskingOriginalCause(): void
    {
        $root = $this->temporaryDirectory();
        $migrations = new class implements MigrationRunnerInterface {
            public function migrate(array $databaseSettings): void
            {
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('transactional')->willThrowException(new \RuntimeException('seed failed'));
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack')->willThrowException(new \RuntimeException('rollback failed'));
        $connection->expects(self::once())->method('close');
        $service = new InstallerService(
            'production',
            new InstallState($root),
            new InstallFilesystem($root),
            $migrations,
            new BootstrapSeeder(),
            new InstallSecretGenerator(2048),
            static fn (array $settings): Connection => $connection,
        );

        try {
            $service->install(InstallInput::fromArray(InstallTestInput::valid(), false), false, null, null, 'rollback-failed');
            self::fail('Expected database rollback failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installation database rollback failed.', $exception->getMessage());
            self::assertSame('seed failed', $exception->getPrevious()?->getMessage());
        }
    }

    public function testMissingEnvironmentDerivesProductionForRemoteInstall(): void
    {
        $root = $this->temporaryDirectory();
        $migrations = new class implements MigrationRunnerInterface {
            public function migrate(array $databaseSettings): void
            {
            }
        };
        $method = new \ReflectionMethod(InstallerService::class, 'installedEnvironment');

        self::assertSame('production', $method->invoke($this->service($root, '', $migrations, $root . '/unused.sqlite'), false));
        self::assertSame('production', $method->invoke($this->service($root, 'prod', $migrations, $root . '/unused.sqlite'), false));
        self::assertSame('staging', $method->invoke($this->service($root, 'staging', $migrations, $root . '/unused.sqlite'), false));
    }

    public function testRemoteLocalAndUnknownEnvironmentsFailClosed(): void
    {
        $root = $this->temporaryDirectory();
        $migrations = new class implements MigrationRunnerInterface {
            public function migrate(array $databaseSettings): void
            {
            }
        };
        $method = new \ReflectionMethod(InstallerService::class, 'installedEnvironment');

        foreach ([
            ['local', 'unsafe_remote_environment'],
            ['testing', 'unsafe_remote_environment'],
            ['custom', 'invalid_app_environment'],
        ] as [$environment, $errorCode]) {
            try {
                $method->invoke($this->service($root, $environment, $migrations, $root . '/unused.sqlite'), false);
                self::fail('Expected remote environment to be rejected.');
            } catch (InstallHttpException $exception) {
                self::assertSame(422, $exception->statusCode);
                self::assertSame($errorCode, $exception->errorCode);
            }
        }
    }

    public function testLoopbackLocalEnvironmentRemainsLocal(): void
    {
        $root = $this->temporaryDirectory();
        $migrations = new class implements MigrationRunnerInterface {
            public function migrate(array $databaseSettings): void
            {
            }
        };
        $method = new \ReflectionMethod(InstallerService::class, 'installedEnvironment');

        self::assertSame('local', $method->invoke($this->service($root, 'local', $migrations, $root . '/unused.sqlite'), true));
        self::assertSame('local', $method->invoke($this->service($root, '', $migrations, $root . '/unused.sqlite'), true));
        self::assertSame('testing', $method->invoke($this->service($root, 'test', $migrations, $root . '/unused.sqlite'), true));
        self::assertSame('staging', $method->invoke($this->service($root, 'staging', $migrations, $root . '/unused.sqlite'), true));
        self::assertSame('production', $method->invoke($this->service($root, 'production', $migrations, $root . '/unused.sqlite'), true));

        try {
            $method->invoke($this->service($root, 'custom', $migrations, $root . '/unused.sqlite'), true);
            self::fail('Expected unsupported loopback environment to be rejected.');
        } catch (InstallHttpException $exception) {
            self::assertSame('invalid_app_environment', $exception->errorCode);
        }
    }

    private function service(string $root, string $environment, MigrationRunnerInterface $migrations, string $databasePath): InstallerService
    {
        return new InstallerService(
            $environment,
            new InstallState($root),
            new InstallFilesystem($root),
            $migrations,
            new BootstrapSeeder(),
            new InstallSecretGenerator(2048),
            static fn (array $settings): Connection => DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ]),
        );
    }

    private function schemaMigration(string $databasePath, bool $dropOauthScopes = false): MigrationRunnerInterface
    {
        return new class($databasePath, $dropOauthScopes) implements MigrationRunnerInterface {
            /** @var array<string, mixed> */
            public array $settings = [];

            public function __construct(private string $databasePath, private bool $dropOauthScopes)
            {
            }

            public function migrate(array $databaseSettings): void
            {
                $this->settings = $databaseSettings;
                $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databasePath]);
                SqliteBootstrapSchema::create($connection);
                if ($this->dropOauthScopes) {
                    $connection->executeStatement('DROP TABLE oauth_scopes');
                }
                $connection->close();
            }
        };
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-installer-service-' . bin2hex(random_bytes(6));
        mkdir($path, 0700, true);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
