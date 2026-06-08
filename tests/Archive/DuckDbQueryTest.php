<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ColdQueryService;

final class DuckDbQueryTest extends TestCase
{
    public function testColdQuerySubmissionPersistsQueuedJobWithoutExecutingSynchronously(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events'))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results');

        $job = $service->submit([
            'sql' => 'select event_type, count(*) as events from archive where date = ? group by event_type',
            'parameters' => ['2026-06-08'],
            'requested_by' => 'admin-user-1',
        ]);
        $stored = $repository->findColdQuery($job->jobId);

        self::assertSame('queued', $job->status);
        self::assertNull($job->completedAt);
        self::assertNull($job->resultObjectKey);
        self::assertSame(0, $job->rowCount);
        self::assertSame($job->jobId, $stored?->jobId);
        self::assertSame('queued', $stored?->status);
    }

    public function testColdQueryWorkerCompletesQueuedJobWithResultMetadata(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
            new ArchiveEvent('click', 'clk-1', new DateTimeImmutable('2026-06-08T11:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events'))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results');
        $queued = $service->submit([
            'sql' => 'select * from archive where date between ? and ?',
            'parameters' => ['2026-06-08', '2026-06-09'],
            'requested_by' => 'admin-user-1',
        ]);

        $completed = $service->runNext();

        self::assertNotNull($completed);
        self::assertSame($queued->jobId, $completed->jobId);
        self::assertSame('completed', $completed->status);
        self::assertSame('json', $completed->resultFormat);
        self::assertSame(2, $completed->rowCount);
        self::assertSame(
            's3://vertoad-archive/query-results/' . $queued->jobId . '.json',
            $completed->resultObjectKey,
        );
        self::assertCount(2, $completed->scannedObjectKeys);
        self::assertNotNull($completed->completedAt);
    }

    public function testColdQueryRejectsInvalidParametersAndReturnsNullWhenQueueIsEmpty(): void
    {
        $repository = new InMemoryArchiveRepository();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results');

        self::assertNull($service->runNext());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('parameters must be an array.');

        $service->submit([
            'sql' => 'select * from archive',
            'parameters' => 'campaign_id=123',
            'requested_by' => 'admin-user-1',
        ]);
    }

    public function testColdQueryRequiresSqlAndRequester(): void
    {
        $service = new ColdQueryService(new InMemoryArchiveRepository(), 's3://vertoad-archive/query-results');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requested_by must be a non-empty string.');

        $service->submit([
            'sql' => 'select * from archive',
            'requested_by' => '',
        ]);
    }
}
