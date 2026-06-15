<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use PHPUnit\Framework\TestCase;
use VertoAD\Service\Cron\PartitionMaintenanceJob;
use VertoAD\Service\Partition\EventTablePartitionMaintainer;

final class PartitionMaintenanceJobTest extends TestCase
{
    public function testMaintainerRejectsInvalidLookaheadMonths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Partition maintenance lookahead months must be positive.');

        new EventTablePartitionMaintainer(
            new RecordingPartitionConnection([], []),
            lookaheadMonths: 0,
        );
    }

    public function testMaintainerReorganizesFuturePartitionForMissingFutureMonths(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p202607', 'p_future'],
                'ad_serving_events' => ['p202606', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 0,
                'ad_serving_events' => 0,
            ],
        );
        $maintainer = new EventTablePartitionMaintainer(
            $connection,
            lookaheadMonths: 3,
            now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
        );

        $metrics = $maintainer->maintain();

        self::assertSame(2, $metrics['tables_checked']);
        self::assertSame(5, $metrics['partitions_created']);
        self::assertSame(2, $metrics['tables_reorganized']);
        self::assertSame(0, $metrics['protected_tables']);
        self::assertSame([
            "ALTER TABLE raw_events REORGANIZE PARTITION p_future INTO (PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'), PARTITION p202609 VALUES LESS THAN ('2026-10-01 00:00:00'), PARTITION p_future VALUES LESS THAN (MAXVALUE))",
            "ALTER TABLE ad_serving_events REORGANIZE PARTITION p_future INTO (PARTITION p202607 VALUES LESS THAN ('2026-08-01 00:00:00'), PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'), PARTITION p202609 VALUES LESS THAN ('2026-10-01 00:00:00'), PARTITION p_future VALUES LESS THAN (MAXVALUE))",
        ], $connection->executedStatements);
    }

    public function testMaintainerIsIdempotentWhenTargetPartitionsAlreadyExist(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p202607', 'p202608', 'p202609', 'p_future'],
                'ad_serving_events' => ['p202606', 'p202607', 'p202608', 'p202609', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 0,
                'ad_serving_events' => 0,
            ],
        );
        $maintainer = new EventTablePartitionMaintainer(
            $connection,
            lookaheadMonths: 3,
            now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
        );

        $metrics = $maintainer->maintain();

        self::assertSame(0, $metrics['partitions_created']);
        self::assertSame(2, $metrics['tables_already_current']);
        self::assertSame([], $connection->executedStatements);
    }

    public function testMaintainerFailsClosedWhenFuturePartitionAlreadyContainsRowsEvenIfLookaheadPartitionsExist(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p202607', 'p202608', 'p202609', 'p_future'],
                'ad_serving_events' => ['p202606', 'p202607', 'p202608', 'p202609', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 3,
                'ad_serving_events' => 0,
            ],
        );
        $maintainer = new EventTablePartitionMaintainer(
            $connection,
            lookaheadMonths: 3,
            now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
        );

        $metrics = $maintainer->maintain();

        self::assertSame(1, $metrics['protected_tables']);
        self::assertSame('raw_events', $metrics['protected_table']);
        self::assertSame(3, $metrics['future_rows']);
        self::assertSame([], $connection->executedStatements);
    }

    public function testMaintainerProtectsFuturePartitionWhenItAlreadyHasRows(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p_future'],
                'ad_serving_events' => ['p202606', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 4,
                'ad_serving_events' => 0,
            ],
        );
        $maintainer = new EventTablePartitionMaintainer(
            $connection,
            lookaheadMonths: 2,
            now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
        );

        $metrics = $maintainer->maintain();

        self::assertSame(1, $metrics['protected_tables']);
        self::assertSame('raw_events', $metrics['protected_table']);
        self::assertSame(4, $metrics['future_rows']);
        self::assertSame(2, $metrics['partitions_created']);
        self::assertSame([
            "ALTER TABLE ad_serving_events REORGANIZE PARTITION p_future INTO (PARTITION p202607 VALUES LESS THAN ('2026-08-01 00:00:00'), PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'), PARTITION p_future VALUES LESS THAN (MAXVALUE))",
        ], $connection->executedStatements);
    }

    public function testPartitionMaintenanceJobReturnsFailedWhenFuturePartitionIsProtected(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p_future'],
                'ad_serving_events' => ['p202606', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 1,
                'ad_serving_events' => 0,
            ],
        );
        $job = new PartitionMaintenanceJob(
            new EventTablePartitionMaintainer(
                $connection,
                lookaheadMonths: 1,
                now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
            ),
        );

        $result = $job->run();

        self::assertSame('partition-maintenance', $result->jobName);
        self::assertSame('failed', $result->status);
        self::assertTrue($result->acquiredLock);
        self::assertSame(1, $result->metrics['protected_tables'] ?? null);
        self::assertStringContainsString('p_future contains rows', (string) $result->message);
    }

    public function testPartitionMaintenanceJobReturnsCompletedWhenTablesAreMaintained(): void
    {
        $connection = new RecordingPartitionConnection(
            partitionsByTable: [
                'raw_events' => ['p202606', 'p202607', 'p_future'],
                'ad_serving_events' => ['p202606', 'p202607', 'p_future'],
            ],
            futureRowsByTable: [
                'raw_events' => 0,
                'ad_serving_events' => 0,
            ],
        );
        $job = new PartitionMaintenanceJob(
            new EventTablePartitionMaintainer(
                $connection,
                lookaheadMonths: 2,
                now: new \DateTimeImmutable('2026-06-15T00:00:00+00:00'),
            ),
        );

        $result = $job->run();

        self::assertSame('partition-maintenance', $result->jobName);
        self::assertSame('completed', $result->status);
        self::assertTrue($result->acquiredLock);
        self::assertSame(2, $result->metrics['tables_checked'] ?? null);
        self::assertSame(2, $result->metrics['partitions_created'] ?? null);
        self::assertSame('Event table partitions were maintained through the configured lookahead window.', $result->message);
    }
}

final class RecordingPartitionConnection extends Connection
{
    /** @var list<string> */
    public array $executedStatements = [];

    /**
     * @param array<string, list<string>> $partitionsByTable
     * @param array<string, int> $futureRowsByTable
     */
    public function __construct(
        private array $partitionsByTable,
        private array $futureRowsByTable,
    ) {
        parent::__construct(['driver' => 'pdo_sqlite', 'memory' => true], new Driver());
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type>|array<string, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type> $types
     */
    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        \PHPUnit\Framework\Assert::assertStringContainsString('information_schema.partitions', strtolower($query));
        $table = (string) ($params['table_name'] ?? '');

        return $this->partitionsByTable[$table] ?? [];
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type>|array<string, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        \PHPUnit\Framework\Assert::assertMatchesRegularExpression('/SELECT COUNT\(\*\) FROM `(raw_events|ad_serving_events)` PARTITION \(p_future\)/', $query);
        preg_match('/FROM `([^`]+)`/', $query, $matches);

        return $this->futureRowsByTable[$matches[1] ?? ''] ?? 0;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type>|array<string, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type> $types
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->executedStatements[] = $sql;

        return 0;
    }
}
