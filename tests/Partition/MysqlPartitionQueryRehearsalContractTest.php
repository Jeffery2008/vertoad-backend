<?php

declare(strict_types=1);

namespace VertoAD\Tests\Partition;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (!defined('VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL_TESTING')) {
    define('VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL_TESTING', true);
}
require_once dirname(__DIR__, 2) . '/scripts/mysql-partition-query-rehearsal.php';

final class MysqlPartitionQueryRehearsalContractTest extends TestCase
{
    public function testScriptUsesInProcessMigrationAndNeverStartsAShell(): void
    {
        $source = $this->source();

        self::assertStringContainsString('new PhinxMigrationRunner($rootPath)', $source);
        self::assertStringNotContainsString('proc_open(', $source);
        self::assertStringNotContainsString('shell_exec(', $source);
        self::assertStringNotContainsString('passthru(', $source);
        self::assertStringNotContainsString('system(', $source);
        self::assertDoesNotMatchRegularExpression('/(?<!->)(?<!::)\bexec\s*\(/', $source);
    }

    public function testCliEntryRunsOnlyAfterRuntimeConstantsAreDeclared(): void
    {
        $source = $this->source();
        $entryOffset = strpos(
            $source,
            "if (!defined('VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL_TESTING'))",
        );
        $queryContractOffset = strpos($source, 'const VERTOAD_PARTITION_REHEARSAL_QUERIES = [');

        self::assertIsInt($entryOffset);
        self::assertIsInt($queryContractOffset);
        self::assertGreaterThan($queryContractOffset, $entryOffset);
    }

    public function testOnlyStrictDisposableDatabaseNamesAreAccepted(): void
    {
        $generated = \mysqlPartitionQueryRehearsalGeneratedDatabaseName();

        self::assertMatchesRegularExpression(
            '/^vertoad_partition_rehearsal_[a-f0-9]{32}$/D',
            $generated,
        );
        self::assertTrue(\mysqlPartitionQueryRehearsalDatabaseNameIsSafe($generated));

        foreach ([
            'vertoad',
            'vertoad_prod',
            'vertoad_production',
            'vertoad_test',
            'production',
            'vertoad_partition_rehearsal_' . str_repeat('a', 31),
            'vertoad_partition_rehearsal_' . str_repeat('a', 32) . ';DROP DATABASE vertoad',
            'vertoad_partition_rehearsal_' . str_repeat('g', 32),
        ] as $databaseName) {
            self::assertFalse(\mysqlPartitionQueryRehearsalDatabaseNameIsSafe($databaseName));
        }
    }

    public function testConfigurationRequiresExplicitExecutionConsent(): void
    {
        $result = \mysqlPartitionQueryRehearsalConfig(['runner.php'], $this->environment());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('--execute', (string) $result['error']);
    }

    public function testConfigurationRejectsProductionDatabaseNameWithoutEchoingSecret(): void
    {
        $secret = 'unit-secret-do-not-print';
        $environment = $this->environment();
        $environment['MYSQL_PARTITION_QUERY_REHEARSAL_PASSWORD'] = $secret;

        $result = \mysqlPartitionQueryRehearsalConfig(
            ['runner.php', '--execute', '--database-name=vertoad_production'],
            $environment,
        );

        self::assertFalse($result['ok']);
        self::assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('vertoad_production', (string) $result['error']);
    }

    public function testConfigurationAcceptsOnlyValidatedScalarConnectionInputs(): void
    {
        $databaseName = 'vertoad_partition_rehearsal_' . str_repeat('a', 32);
        $result = \mysqlPartitionQueryRehearsalConfig(
            [
                'runner.php',
                '--execute',
                '--database-name=' . $databaseName,
                '--connect-timeout-seconds=7',
            ],
            $this->environment(),
        );

        self::assertTrue($result['ok']);
        self::assertSame($databaseName, $result['config']['database_name']);
        self::assertSame(7, $result['config']['connect_timeout_seconds']);
        self::assertSame('127.0.0.1', $result['config']['database']['host']);
        self::assertSame(3306, $result['config']['database']['port']);
        self::assertSame('rehearsal_user', $result['config']['database']['username']);
        self::assertSame('unit-secret', $result['config']['database']['password']);
    }

    public function testConfigurationRejectsDsnHostInjection(): void
    {
        $environment = $this->environment();
        $environment['MYSQL_PARTITION_QUERY_REHEARSAL_HOST'] = '127.0.0.1;dbname=vertoad_production';

        $result = \mysqlPartitionQueryRehearsalConfig(
            ['runner.php', '--execute'],
            $environment,
        );

        self::assertFalse($result['ok']);
        self::assertStringNotContainsString('vertoad_production', (string) $result['error']);
        self::assertFalse(\mysqlPartitionQueryRehearsalHostIsSafe("127.0.0.1\nport=3307"));
        self::assertTrue(\mysqlPartitionQueryRehearsalHostIsSafe('::1'));
        self::assertTrue(\mysqlPartitionQueryRehearsalHostIsSafe('mysql.test.internal'));
    }

    public function testMalformedOrDuplicateArgumentsAreRejectedAsData(): void
    {
        foreach ([
            ['runner.php', '--execute', '--execute'],
            ['runner.php', '--database-name'],
            ['runner.php', '--unknown=value'],
            ['runner.php', '--database-name='],
            ['runner.php', '&&', 'calc.exe'],
        ] as $arguments) {
            try {
                \mysqlPartitionQueryRehearsalOptions($arguments);
                self::fail('Expected malformed arguments to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString(implode(' ', $arguments), $exception->getMessage());
            }
        }
    }

    public function testTimeRangeSqlUsesBoundValuesAndWhitelistedIdentifiers(): void
    {
        foreach (['raw_events', 'ad_serving_events'] as $table) {
            $sql = \mysqlPartitionQueryRehearsalTimeRangeSql($table);
            self::assertSame(2, substr_count($sql, '?'));
            self::assertStringContainsString('occurred_at >= ?', $sql);
            self::assertStringContainsString('occurred_at < ?', $sql);
            self::assertStringNotContainsString('PARTITION (', $sql);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported partition rehearsal table.');
        \mysqlPartitionQueryRehearsalTimeRangeSql('raw_events`; DROP DATABASE vertoad; --');
    }

    public function testExplainPartitionExtractionPreservesOptimizerOrder(): void
    {
        $source = $this->source();
        self::assertStringContainsString("fetchAllAssociative('EXPLAIN ' . \$sql", $source);
        self::assertStringNotContainsString("fetchAllAssociative('EXPLAIN PARTITIONS '", $source);
        self::assertSame(
            ['p202612', 'p202701', 'p202702'],
            \mysqlPartitionQueryRehearsalExtractPartitions([
                ['partitions' => 'p202612,p202701'],
                ['partitions' => 'p202701,p202702'],
            ]),
        );
    }

    public function testExplainPartitionAssertionRejectsFutureOrExtraPartitions(): void
    {
        try {
            \mysqlPartitionQueryRehearsalAssertPartitions(
                ['p202701', 'p_future'],
                ['p202701'],
            );
            self::fail('Expected p_future to fail the pruning contract.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('p_future', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected partition set');
        \mysqlPartitionQueryRehearsalAssertPartitions(
            ['p202612', 'p202701'],
            ['p202701'],
        );
    }

    public function testMaintenanceContractsRequireCreationThenIdempotence(): void
    {
        \mysqlPartitionQueryRehearsalAssertFirstMaintenance([
            'tables_checked' => 2,
            'partitions_created' => 6,
            'tables_reorganized' => 2,
            'tables_already_current' => 0,
            'protected_tables' => 0,
        ]);
        \mysqlPartitionQueryRehearsalAssertSecondMaintenance([
            'tables_checked' => 2,
            'partitions_created' => 0,
            'tables_reorganized' => 0,
            'tables_already_current' => 2,
            'protected_tables' => 0,
        ]);
        self::addToAssertionCount(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not idempotent');
        \mysqlPartitionQueryRehearsalAssertSecondMaintenance([
            'tables_checked' => 2,
            'partitions_created' => 1,
            'tables_reorganized' => 1,
            'tables_already_current' => 1,
            'protected_tables' => 0,
        ]);
    }

    public function testSecretRedactionCoversRawAndUrlEncodedForms(): void
    {
        $secret = 'pa$$ word/with?symbols';
        $output = \mysqlPartitionQueryRehearsalRedact(
            'raw=' . $secret . ' encoded=' . rawurlencode($secret),
            [$secret, rawurlencode($secret)],
        );

        self::assertSame('raw=[REDACTED] encoded=[REDACTED]', $output);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringNotContainsString(rawurlencode($secret), $output);
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'MYSQL_PARTITION_QUERY_REHEARSAL_HOST' => '127.0.0.1',
            'MYSQL_PARTITION_QUERY_REHEARSAL_PORT' => '3306',
            'MYSQL_PARTITION_QUERY_REHEARSAL_USERNAME' => 'rehearsal_user',
            'MYSQL_PARTITION_QUERY_REHEARSAL_PASSWORD' => 'unit-secret',
        ];
    }

    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/scripts/mysql-partition-query-rehearsal.php');
        self::assertIsString($source);

        return $source;
    }
}
