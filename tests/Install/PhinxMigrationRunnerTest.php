<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VertoAD\Install\PhinxMigrationRunner;

final class PhinxMigrationRunnerTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testRunsRealParameterizedPhinxMigrationAgainstSqlite(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-phinx-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/db/migrations', 0700, true);
        mkdir($this->root . '/db/seeds', 0700, true);
        file_put_contents($this->root . '/db/migrations/20260710000000_create_installer_runner_probe.php', <<<'PHP'
<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;
final class CreateInstallerRunnerProbe extends AbstractMigration
{
    public function change(): void
    {
        $this->table('installer_runner_probe')->addColumn('value', 'string')->create();
    }
}
PHP);
        $databasePath = $this->root . '/installer.sqlite';

        (new PhinxMigrationRunner($this->root))->migrate([
            'driver' => 'pdo_sqlite',
            'path' => $databasePath,
        ]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath . '.sqlite3']);
        self::assertTrue($connection->createSchemaManager()->tablesExist(['installer_runner_probe', 'phinxlog']));
        $connection->close();
    }

    public function testBuildsMysqlEnvironmentFromParametersWithoutDsnOrSqlReplacement(): void
    {
        $method = new ReflectionMethod(PhinxMigrationRunner::class, 'phinxEnvironment');
        $environment = $method->invoke(new PhinxMigrationRunner(__DIR__), [
            'driver' => 'pdo_mysql',
            'host' => 'db.example',
            'database' => 'vertoad',
            'username' => 'app',
            'password' => 'p@ss$word;not-shell',
            'port' => 3307,
            'charset' => 'utf8mb4',
        ]);

        self::assertSame([
            'adapter' => 'mysql',
            'host' => 'db.example',
            'name' => 'vertoad',
            'user' => 'app',
            'pass' => 'p@ss$word;not-shell',
            'port' => 3307,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ], $environment);
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Install/PhinxMigrationRunner.php');
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('string_replace', $source);
    }

    public function testRejectsMissingSqlitePathAndUnsupportedDriver(): void
    {
        $method = new ReflectionMethod(PhinxMigrationRunner::class, 'phinxEnvironment');
        $runner = new PhinxMigrationRunner(__DIR__);

        foreach ([
            [['driver' => 'pdo_sqlite'], 'SQLite migration path is required.'],
            [['driver' => 'pgsql'], 'only supports pdo_mysql'],
        ] as [$settings, $message]) {
            try {
                $method->invoke($runner, $settings);
                self::fail('Expected migration settings to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
