<?php

declare(strict_types=1);

namespace VertoAD\Install;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final readonly class PhinxMigrationRunner implements MigrationRunnerInterface
{
    public function __construct(private string $rootPath)
    {
    }

    public function migrate(#[\SensitiveParameter] array $databaseSettings): void
    {
        $config = new Config([
            'paths' => [
                'migrations' => $this->rootPath . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations',
                'seeds' => $this->rootPath . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'seeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'install',
                'install' => $this->phinxEnvironment($databaseSettings),
            ],
            'version_order' => 'creation',
        ]);
        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('install');
    }

    /** @param array<string, mixed> $settings */
    private function phinxEnvironment(#[\SensitiveParameter] array $settings): array
    {
        $driver = (string) ($settings['driver'] ?? '');
        if ($driver === 'pdo_sqlite') {
            $path = trim((string) ($settings['path'] ?? ''));
            if ($path === '') {
                throw new \InvalidArgumentException('SQLite migration path is required.');
            }

            return ['adapter' => 'sqlite', 'name' => $path];
        }

        if ($driver !== 'pdo_mysql') {
            throw new \InvalidArgumentException('The installer only supports pdo_mysql database settings.');
        }

        return [
            'adapter' => 'mysql',
            'host' => (string) $settings['host'],
            'name' => (string) $settings['database'],
            'user' => (string) $settings['username'],
            'pass' => (string) $settings['password'],
            'port' => (int) $settings['port'],
            'charset' => (string) ($settings['charset'] ?? 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }
}
