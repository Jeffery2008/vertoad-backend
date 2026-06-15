<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;

define('VERTOAD_MYSQL_PARTITION_REHEARSAL_TESTING', true);
require_once dirname(__DIR__, 2) . '/scripts/mysql-partition-rehearsal.php';

final class MysqlPartitionRehearsalScriptTest extends TestCase
{
    public function testRehearsalRequiresExplicitOptIn(): void
    {
        $result = mysqlPartitionRehearsalConfig(
            ['mysql-partition-rehearsal.php'],
            ['VERTOAD_MYSQL_PARTITION_REHEARSAL' => '0'],
            $this->rootPath(),
            $this->phinxConfig('vertoad_partition_rehearsal'),
        );

        self::assertFalse($result['ok']);
        self::assertSame('Set VERTOAD_MYSQL_PARTITION_REHEARSAL=1 before running the MySQL partition rehearsal.', $result['error']);
    }

    public function testRehearsalRejectsProductionLookingDatabaseNames(): void
    {
        $result = mysqlPartitionRehearsalConfig(
            ['mysql-partition-rehearsal.php'],
            [
                'VERTOAD_MYSQL_PARTITION_REHEARSAL' => '1',
                'VERTOAD_MYSQL_PARTITION_REHEARSAL_ALLOW_NON_TEST_DB' => '0',
            ],
            $this->rootPath(),
            $this->phinxConfig('vertoad'),
        );

        self::assertFalse($result['ok']);
        self::assertSame(
            'Refusing to run partition rehearsal against database "vertoad"; use a test/dev/rehearsal database name or set VERTOAD_MYSQL_PARTITION_REHEARSAL_ALLOW_NON_TEST_DB=1.',
            $result['error'],
        );
    }

    public function testRehearsalBuildsPhinxCommandForSafeTestingDatabase(): void
    {
        $result = mysqlPartitionRehearsalConfig(
            ['mysql-partition-rehearsal.php', '--environment=testing', '--lookahead-months=5', '--reset-database'],
            [
                'VERTOAD_MYSQL_PARTITION_REHEARSAL' => '1',
                'VERTOAD_MYSQL_PARTITION_REHEARSAL_CONNECT_TIMEOUT_SECONDS' => '4',
            ],
            $this->rootPath(),
            $this->phinxConfig('vertoad_partition_rehearsal'),
        );

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame('testing', $result['config']['environment']);
        self::assertSame(5, $result['config']['lookahead_months']);
        self::assertTrue($result['config']['reset_database']);
        self::assertSame(4, $result['config']['connect_timeout_seconds']);
        self::assertSame(4, $result['config']['database']['attr_timeout']);
        self::assertSame('vertoad_partition_rehearsal', $result['config']['database']['name']);
        self::assertSame([
            PHP_BINARY,
            $this->rootPath() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phinx',
            'migrate',
            '-e',
            'testing',
            '-c',
            $this->rootPath() . DIRECTORY_SEPARATOR . 'phinx.php',
        ], mysqlPartitionRehearsalPhinxCommand($result['config']));
    }

    public function testRehearsalRejectsInvalidConnectTimeout(): void
    {
        $result = mysqlPartitionRehearsalConfig(
            ['mysql-partition-rehearsal.php'],
            [
                'VERTOAD_MYSQL_PARTITION_REHEARSAL' => '1',
                'VERTOAD_MYSQL_PARTITION_REHEARSAL_CONNECT_TIMEOUT_SECONDS' => '0',
            ],
            $this->rootPath(),
            $this->phinxConfig('vertoad_partition_rehearsal'),
        );

        self::assertFalse($result['ok']);
        self::assertSame('Partition rehearsal connect timeout seconds must be positive.', $result['error']);
    }

    public function testRehearsalAcceptsExplicitNowForRepeatablePartitionDrills(): void
    {
        $result = mysqlPartitionRehearsalConfig(
            [
                'mysql-partition-rehearsal.php',
                '--environment=testing',
                '--lookahead-months=3',
                '--now=2026-10-15T00:00:00+00:00',
            ],
            ['VERTOAD_MYSQL_PARTITION_REHEARSAL' => '1'],
            $this->rootPath(),
            $this->phinxConfig('vertoad_partition_rehearsal'),
        );

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertInstanceOf(\DateTimeImmutable::class, $result['config']['now']);
        self::assertSame('2026-10-15T00:00:00+00:00', $result['config']['now']->format(DATE_ATOM));
        self::assertSame(
            ['p202611', 'p202612', 'p202701'],
            mysqlPartitionRehearsalExpectedPartitionNames(3, $result['config']['now']),
        );
    }

    public function testSafeDatabaseNamesAcceptDevTestSandboxAndRehearsalNames(): void
    {
        foreach (['vertoad_test', 'vertoad_testing', 'vertoad_dev', 'vertoad_local', 'vertoad_sandbox', 'vertoad_rehearsal'] as $name) {
            self::assertTrue(mysqlPartitionRehearsalDatabaseNameIsSafe($name), $name);
        }

        self::assertFalse(mysqlPartitionRehearsalDatabaseNameIsSafe('vertoad_prod'));
    }

    /**
     * @return array<string, mixed>
     */
    private function phinxConfig(string $database): array
    {
        return [
            'environments' => [
                'default_environment' => 'testing',
                'testing' => [
                    'adapter' => 'mysql',
                    'host' => '127.0.0.1',
                    'name' => $database,
                    'user' => 'vertoad',
                    'pass' => 'secret',
                    'port' => 3306,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
            ],
        ];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }
}
