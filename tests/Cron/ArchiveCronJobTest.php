<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\FixtureColdQueryRunner;
use VertoAD\Service\Cron\ArchiveParquetJob;
use VertoAD\Service\Cron\DuckDbColdQueryJob;

final class ArchiveCronJobTest extends TestCase
{
    public function testArchiveParquetCronJobReportsManifestMetrics(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
            new ArchiveEvent('click', 'clk-1', new DateTimeImmutable('2026-06-08T10:30:00+00:00'), ['campaign_id' => 123]),
        ]);
        $job = new ArchiveParquetJob(new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()));

        $result = $job->run();

        self::assertSame('archive-parquet', $job->name());
        self::assertSame('archive-parquet', $result->jobName);
        self::assertSame('completed', $result->status);
        self::assertSame('parquet-archive', $result->metrics['archive_job'] ?? null);
        self::assertSame(2, $result->metrics['events_archived'] ?? null);
        self::assertSame(2, $result->metrics['partitions'] ?? null);
        self::assertIsString($result->metrics['manifest_id'] ?? null);
        self::assertSame('Raw serving events were archived into Parquet partition manifests.', $result->message);
    }

    public function testDuckDbColdQueryCronJobCompletesOneQueuedQuery(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FixtureColdQueryRunner());
        $queued = $service->submit([
            'sql' => 'select * from parquet_scan(?)',
            'parameters' => ['event_type=impression'],
            'requested_by' => 'ops@example.test',
        ]);
        $job = new DuckDbColdQueryJob($service);

        $result = $job->run();

        self::assertSame('duckdb-cold-query', $job->name());
        self::assertSame('duckdb-cold-query', $result->jobName);
        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame($queued->jobId, $result->metrics['job_id'] ?? null);
        self::assertSame(1, $result->metrics['row_count'] ?? null);
        self::assertSame(1, $result->metrics['scanned_objects'] ?? null);
        self::assertSame('completed', $repository->findColdQuery($queued->jobId)?->status);
        self::assertSame('A queued DuckDB cold query job was completed.', $result->message);
    }

    public function testDuckDbColdQueryCronJobReportsRunnerFailure(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FixtureColdQueryRunner([], failMessage: 'fixture rejected query'));
        $queued = $service->submit([
            'sql' => 'delete from archive',
            'parameters' => [],
            'requested_by' => 'ops@example.test',
        ]);
        $job = new DuckDbColdQueryJob($service);

        $result = $job->run();

        self::assertSame('failed', $result->status);
        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame($queued->jobId, $result->metrics['job_id'] ?? null);
        self::assertSame('fixture rejected query', $result->metrics['error_message'] ?? null);
        self::assertSame('failed', $repository->findColdQuery($queued->jobId)?->status);
        self::assertSame('A queued DuckDB cold query job failed.', $result->message);
    }

    public function testDuckDbColdQueryCronJobReportsEmptyQueue(): void
    {
        $job = new DuckDbColdQueryJob(new ColdQueryService(new InMemoryArchiveRepository(), 's3://vertoad-archive/query-results', new FixtureColdQueryRunner()));

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(0, $result->metrics['processed'] ?? null);
        self::assertSame(0, $result->metrics['queued'] ?? null);
        self::assertSame('No queued DuckDB cold query job was available.', $result->message);
    }
}
