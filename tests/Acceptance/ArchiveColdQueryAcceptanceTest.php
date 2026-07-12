<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Tests\Acceptance\ArchiveColdQuery\ArchiveColdQueryAcceptanceHarness;

#[Group('external-tools-integration')]
#[Group('archive-cold-query-acceptance')]
final class ArchiveColdQueryAcceptanceTest extends TestCase
{
    private ?ArchiveColdQueryAcceptanceHarness $harness = null;
    private bool $scenarioCompleted = false;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_ARCHIVE_COLD_QUERY_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_ARCHIVE_COLD_QUERY_ACCEPTANCE=1 with real MySQL 8, DuckDB, and S3-compatible settings.',
            );
        }

        $this->harness = ArchiveColdQueryAcceptanceHarness::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->harness === null) {
            return;
        }

        $evidence = $this->harness->cleanup();
        self::assertSame($evidence['objects_before'], $evidence['objects_deleted']);
        self::assertSame(0, $evidence['objects_after']);
        self::assertSame(0, $evidence['database_after']);
        self::assertSame(0, $evidence['workspace_after']);
        if ($this->scenarioCompleted) {
            self::assertSame(7, $evidence['objects_before']);
            self::assertSame(1, $evidence['database_before']);
            self::assertSame(1, $evidence['workspace_before']);
        }
        $this->harness = null;
    }

    public function testMysqlHotEventsArchiveToRealR2ParquetAndReturnThroughQueuedDuckDbQuery(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+\b/', $harness->duckDbVersion());
        self::assertMatchesRegularExpression('/^vertoad_archive_acceptance_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertMatchesRegularExpression('~^acceptance/archive-cold-query/[a-f0-9]{16}$~', $harness->objectPrefix());
        self::assertGreaterThanOrEqual(27, $harness->migrationCount());
        self::assertSame(4, $harness->pendingEventCount());
        self::assertSame(0, $harness->processedEventCount());
        self::assertSame([], $harness->objectKeys());

        $firstRun = $harness->archiveJob()->run();
        $this->assertArchiveCronResult($firstRun->jobName, $firstRun->status, $firstRun->metrics, 4, 3);
        $firstManifest = $this->requiredManifest((string) ($firstRun->metrics['manifest_id'] ?? ''));
        $this->assertManifest($firstManifest);
        self::assertSame(0, $harness->pendingEventCount());
        self::assertSame(4, $harness->processedEventCount());
        self::assertCount(3, $harness->objectKeys());

        $harness->seedSecondBatch();
        self::assertSame(4, $harness->pendingEventCount());
        $secondRun = $harness->archiveJob()->run();
        $this->assertArchiveCronResult($secondRun->jobName, $secondRun->status, $secondRun->metrics, 4, 3);
        $secondManifest = $this->requiredManifest((string) ($secondRun->metrics['manifest_id'] ?? ''));
        self::assertNotSame($firstManifest->manifestId, $secondManifest->manifestId);
        $this->assertManifest($secondManifest);
        self::assertSame(0, $harness->pendingEventCount());
        self::assertSame(8, $harness->processedEventCount());
        self::assertCount(2, $harness->repository()->manifests());
        self::assertCount(6, $harness->objectKeys());

        $firstNoOp = $harness->archiveJob()->run();
        $secondNoOp = $harness->archiveJob()->run();
        $this->assertArchiveCronResult($firstNoOp->jobName, $firstNoOp->status, $firstNoOp->metrics, 0, 0);
        $this->assertArchiveCronResult($secondNoOp->jobName, $secondNoOp->status, $secondNoOp->metrics, 0, 0);
        self::assertSame($firstNoOp->metrics['manifest_id'] ?? null, $secondNoOp->metrics['manifest_id'] ?? null);
        self::assertCount(3, $harness->repository()->manifests());
        self::assertCount(6, $harness->objectKeys());

        $queued = $harness->coldQueryService()->submit([
            'sql' => 'SELECT event_id, event_type FROM archive WHERE CAST(date AS VARCHAR) = ? ORDER BY event_id',
            'parameters' => ['2026-06-08'],
            'requested_by' => 'archive-acceptance-' . $harness->databaseName(),
        ]);
        self::assertSame('queued', $queued->status);
        self::assertNull($queued->completedAt);
        self::assertNull($queued->resultObjectKey);
        self::assertSame('queued', $harness->repository()->findColdQuery($queued->jobId)?->status);

        $queryRun = $harness->coldQueryJob()->run();
        self::assertSame('duckdb-cold-query', $queryRun->jobName);
        self::assertSame('completed', $queryRun->status);
        self::assertSame(1, $queryRun->metrics['processed'] ?? null);
        self::assertSame($queued->jobId, $queryRun->metrics['job_id'] ?? null);
        self::assertSame(8, $queryRun->metrics['row_count'] ?? null);
        self::assertSame(6, $queryRun->metrics['scanned_objects'] ?? null);

        $completed = $harness->repository()->findColdQuery($queued->jobId);
        self::assertNotNull($completed);
        self::assertSame('completed', $completed->status);
        self::assertSame(8, $completed->rowCount);
        self::assertNotNull($completed->completedAt);
        self::assertNull($completed->errorMessage);
        self::assertNotNull($completed->resultObjectKey);
        self::assertStringStartsWith($harness->objectPrefix() . '/query-results/', $completed->resultObjectKey);
        self::assertCount(6, $completed->scannedObjectKeys);
        $expectedScannedKeys = array_values(array_unique(array_merge(
            array_column($firstManifest->partitions, 'object_key'),
            array_column($secondManifest->partitions, 'object_key'),
        )));
        $actualScannedKeys = $completed->scannedObjectKeys;
        sort($expectedScannedKeys);
        sort($actualScannedKeys);
        self::assertSame($expectedScannedKeys, $actualScannedKeys);

        $resultBody = $harness->objectBody($completed->resultObjectKey);
        $resultRows = json_decode($resultBody, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($harness->expectedRows(), $resultRows);
        self::assertSame([
            'content_type' => 'application/json',
            'content_length' => strlen($resultBody),
        ], $harness->objectMetadata($completed->resultObjectKey));
        self::assertCount(7, $harness->objectKeys());
        self::assertSame([], $harness->workspaceEntries());

        $storedBeforeNoOp = $harness->connection()->fetchAssociative(
            'SELECT status, row_count, result_object_key, scanned_object_keys_json, completed_at '
            . 'FROM archive_cold_query_jobs WHERE job_id = ?',
            [$queued->jobId],
        );
        $objectsBeforeNoOp = $harness->objectKeys();
        $emptyQueryRun = $harness->coldQueryJob()->run();
        self::assertSame('completed', $emptyQueryRun->status);
        self::assertSame(0, $emptyQueryRun->metrics['processed'] ?? null);
        self::assertSame(0, $emptyQueryRun->metrics['queued'] ?? null);
        self::assertSame($storedBeforeNoOp, $harness->connection()->fetchAssociative(
            'SELECT status, row_count, result_object_key, scanned_object_keys_json, completed_at '
            . 'FROM archive_cold_query_jobs WHERE job_id = ?',
            [$queued->jobId],
        ));
        self::assertSame($objectsBeforeNoOp, $harness->objectKeys());
        self::assertSame([], $harness->workspaceEntries());

        $this->scenarioCompleted = true;
    }

    /** @param array<string, mixed> $metrics */
    private function assertArchiveCronResult(
        string $jobName,
        string $status,
        array $metrics,
        int $events,
        int $partitions,
    ): void {
        self::assertSame('archive-parquet', $jobName);
        self::assertSame('completed', $status);
        self::assertSame('parquet-archive', $metrics['archive_job'] ?? null);
        self::assertSame($events, $metrics['events_archived'] ?? null);
        self::assertSame($partitions, $metrics['partitions'] ?? null);
        self::assertMatchesRegularExpression('/^manifest_[a-f0-9]{40,64}$/', (string) ($metrics['manifest_id'] ?? ''));
    }

    private function assertManifest(ArchiveManifest $manifest): void
    {
        $harness = $this->harness();
        self::assertSame('completed', $manifest->status);
        self::assertSame('parquet', $manifest->format);
        self::assertSame(4, $manifest->eventCount);
        self::assertSame([
            'event_type=click/date=2026-06-08/hour=10' => 2,
            'event_type=impression/date=2026-06-08/hour=10' => 1,
            'event_type=video_start/date=2026-06-08/hour=11' => 1,
        ], array_column($manifest->partitions, 'event_count', 'partition'));

        foreach ($manifest->partitions as $partition) {
            $objectKey = (string) ($partition['object_key'] ?? '');
            self::assertStringStartsWith($harness->objectPrefix() . '/raw-events/', $objectKey);
            self::assertStringEndsWith('.parquet', $objectKey);
            self::assertSame($partition['event_count'], $partition['row_count'] ?? null);
            self::assertGreaterThan(0, $partition['byte_count'] ?? 0);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', (string) ($partition['checksum'] ?? ''));

            $body = $harness->objectBody($objectKey);
            self::assertStringStartsWith('PAR1', $body);
            self::assertStringEndsWith('PAR1', $body);
            self::assertSame(strlen($body), $partition['byte_count']);
            self::assertSame('sha256:' . hash('sha256', $body), $partition['checksum']);
            self::assertSame([
                'content_type' => 'application/vnd.apache.parquet',
                'content_length' => strlen($body),
            ], $harness->objectMetadata($objectKey));
        }

        $stored = $harness->repository()->findManifest($manifest->manifestId);
        self::assertNotNull($stored);
        self::assertSame($manifest->partitions, $stored->partitions);
        self::assertSame(1, (int) $harness->connection()->fetchOne(
            'SELECT COUNT(*) FROM archive_manifests WHERE manifest_id = ? AND status = ? AND format = ?',
            [$manifest->manifestId, 'completed', 'parquet'],
        ));
    }

    private function requiredManifest(string $manifestId): ArchiveManifest
    {
        self::assertNotSame('', $manifestId);
        $manifest = $this->harness()->repository()->findManifest($manifestId);
        self::assertNotNull($manifest);

        return $manifest;
    }

    private function harness(): ArchiveColdQueryAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The archive cold-query acceptance harness is unavailable.');
    }
}
