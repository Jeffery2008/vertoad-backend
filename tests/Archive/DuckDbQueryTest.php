<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ColdQueryExecutionRequest;
use VertoAD\Service\Archive\ColdQueryExecutionResult;
use VertoAD\Service\Archive\ColdQueryRunnerInterface;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\FixtureColdQueryRunner;

final class DuckDbQueryTest extends TestCase
{
    public function testColdQuerySubmissionPersistsQueuedJobWithoutExecutingSynchronously(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FixtureColdQueryRunner());

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
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()))->run();
        $runner = new RecordingColdQueryRunner(new FixtureColdQueryRunner([
            'select * from archive where date between ? and ?' => 7,
        ]));
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', $runner);
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
        self::assertSame(7, $completed->rowCount);
        self::assertSame(
            's3://vertoad-archive/query-results/' . $queued->jobId . '.json',
            $completed->resultObjectKey,
        );
        self::assertCount(2, $completed->scannedObjectKeys);
        self::assertNotNull($completed->completedAt);
        self::assertNull($completed->errorMessage);
        self::assertSame('running', $runner->observedStatus);
        self::assertSame($queued->jobId, $runner->request?->jobId);
        self::assertSame($queued->parameters, $runner->request?->parameters);
        self::assertSame($completed->scannedObjectKeys, $runner->request?->objectKeys);
    }

    public function testColdQueryWorkerPersistsFailedStatusAndErrorMessageWhenRunnerFails(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        (new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()))->run();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FailingColdQueryRunner());
        $queued = $service->submit([
            'sql' => 'select * from archive where date = ?',
            'parameters' => ['2026-06-08'],
            'requested_by' => 'admin-user-1',
        ]);

        $failed = $service->runNext();

        self::assertNotNull($failed);
        self::assertSame($queued->jobId, $failed->jobId);
        self::assertSame('failed', $failed->status);
        self::assertSame('fixture runner failed', $failed->errorMessage);
        self::assertSame(0, $failed->rowCount);
        self::assertNull($failed->resultObjectKey);
        self::assertSame('failed', $repository->findColdQuery($queued->jobId)?->status);
        self::assertSame('fixture runner failed', $repository->findColdQuery($queued->jobId)?->errorMessage);
    }

    public function testColdQueryWorkerOnlyScansCompletedManifests(): void
    {
        $repository = new InMemoryArchiveRepository();
        $repository->saveManifest(new ArchiveManifest(
            manifestId: 'manifest_pending',
            status: 'running',
            format: 'parquet',
            eventCount: 1,
            partitions: [[
                'partition' => 'event_type=click/date=2026-06-08/hour=10',
                'object_key' => 's3://vertoad-archive/raw-events/pending.parquet',
                'event_count' => 1,
            ]],
            createdAt: new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        ));
        $runner = new RecordingColdQueryRunner(new FixtureColdQueryRunner());
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', $runner);
        $service->submit([
            'sql' => 'select * from archive',
            'requested_by' => 'admin-user-1',
        ]);

        $completed = $service->runNext();

        self::assertNotNull($completed);
        self::assertSame('completed', $completed->status);
        self::assertSame([], $completed->scannedObjectKeys);
        self::assertSame([], $runner->request?->objectKeys);
    }

    public function testFixtureColdQueryRunnerRejectsNonSelectAndMultipleStatements(): void
    {
        $runner = new FixtureColdQueryRunner();

        foreach (['delete from archive', 'select * from archive; drop table raw_events'] as $sql) {
            try {
                $runner->run(new ColdQueryExecutionRequest(
                    jobId: 'cold_query_rejected',
                    sql: $sql,
                    parameters: [],
                    objectKeys: [],
                    resultObjectKey: 's3://vertoad-archive/query-results/rejected.json',
                    currentStatus: 'running',
                ));
                self::fail('Expected unsafe fixture SQL to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Cold query runner only accepts a single SELECT fixture query.', $exception->getMessage());
            }
        }
    }

    public function testColdQueryRejectsInvalidParametersAndReturnsNullWhenQueueIsEmpty(): void
    {
        $repository = new InMemoryArchiveRepository();
        $service = new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FixtureColdQueryRunner());

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
        $service = new ColdQueryService(new InMemoryArchiveRepository(), 's3://vertoad-archive/query-results', new FixtureColdQueryRunner());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requested_by must be a non-empty string.');

        $service->submit([
            'sql' => 'select * from archive',
            'requested_by' => '',
        ]);
    }
}

final class RecordingColdQueryRunner implements ColdQueryRunnerInterface
{
    public ?ColdQueryExecutionRequest $request = null;
    public ?string $observedStatus = null;

    public function __construct(private readonly ColdQueryRunnerInterface $inner)
    {
    }

    public function run(ColdQueryExecutionRequest $request): ColdQueryExecutionResult
    {
        $this->request = $request;
        $this->observedStatus = $request->currentStatus;

        return $this->inner->run($request);
    }
}

final class FailingColdQueryRunner implements ColdQueryRunnerInterface
{
    public function run(ColdQueryExecutionRequest $request): ColdQueryExecutionResult
    {
        throw new \RuntimeException('fixture runner failed');
    }
}
