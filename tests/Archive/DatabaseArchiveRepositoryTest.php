<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Domain\Archive\ColdQueryJob;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;

final class DatabaseArchiveRepositoryTest extends TestCase
{
    public function testReadsPendingRawEventsAndPersistsManifests(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseArchiveRepository($connection);
        $this->insertRawEvent($connection, 'impression:shared-1', 'impression', '2026-06-08 10:15:00', ['campaign_id' => 123, 'event_id' => 'shared-1']);
        $this->insertRawEvent($connection, 'click:shared-1', 'click', '2026-06-08 10:30:00', ['campaign_id' => 123, 'event_id' => 'shared-1']);
        $this->insertRawEvent($connection, 'done-1', 'click', '2026-06-08 10:35:00', ['campaign_id' => 999], processed: true);

        $events = $repository->pendingEvents();

        self::assertSame(['impression:shared-1', 'click:shared-1'], array_map(static fn ($event): string => $event->eventId, $events));
        self::assertSame(['campaign_id' => 123, 'event_id' => 'shared-1'], $events[0]->payload);
        self::assertSame('2026-06-08T10:15:00+00:00', $events[0]->occurredAt->format(DATE_ATOM));
        $repository->markEventsArchived(['impression:shared-1', 'click:shared-1'], new DateTimeImmutable('2026-06-09T08:05:00+00:00'));
        $repository->markEventsArchived([], new DateTimeImmutable('2026-06-09T08:10:00+00:00'));
        self::assertSame([], $repository->pendingEvents());
        self::assertSame(
            '2026-06-09 08:05:00',
            $connection->fetchOne('SELECT processed_at FROM raw_events WHERE event_uuid = ?', ['impression:shared-1']),
        );
        self::assertSame(
            '2026-06-09 08:05:00',
            $connection->fetchOne('SELECT processed_at FROM raw_events WHERE event_uuid = ?', ['click:shared-1']),
        );

        $manifest = new ArchiveManifest(
            manifestId: 'manifest_1',
            status: 'completed',
            format: 'parquet',
            eventCount: 2,
            partitions: [[
                'partition' => 'event_type=click/date=2026-06-08/hour=10',
                'object_key' => 's3://archive/click.parquet',
                'event_count' => 1,
            ]],
            createdAt: new DateTimeImmutable('2026-06-09T08:00:00+00:00', new DateTimeZone('UTC')),
        );

        self::assertSame($manifest, $repository->saveManifest($manifest));
        self::assertSame(
            '2026-06-09 08:00:00',
            $connection->fetchOne('SELECT created_at FROM archive_manifests WHERE manifest_id = ?', ['manifest_1']),
        );
        $fresh = new DatabaseArchiveRepository($connection);

        self::assertSame('manifest_1', $fresh->findManifest('manifest_1')?->manifestId);
        self::assertSame($manifest->partitions, $fresh->findManifest('manifest_1')?->partitions);
        $repository->saveManifest(new ArchiveManifest(
            manifestId: 'manifest_1',
            status: 'superseded',
            format: 'parquet',
            eventCount: 0,
            partitions: [],
            createdAt: new DateTimeImmutable('2026-06-09T09:00:00+00:00', new DateTimeZone('UTC')),
        ));
        self::assertSame('superseded', $fresh->findManifest('manifest_1')?->status);
        self::assertSame(['manifest_1'], array_map(
            static fn (ArchiveManifest $stored): string => $stored->manifestId,
            $fresh->manifests(),
        ));
        self::assertNull($fresh->findManifest('missing'));
    }

    public function testPersistsColdQueriesAndReturnsNextQueuedJobInCreatedOrder(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseArchiveRepository($connection);
        $repository->saveColdQuery($this->coldQuery('query_2', createdAt: '2026-06-09T08:10:00+00:00'));
        $repository->saveColdQuery($this->coldQuery('query_1', createdAt: '2026-06-09T08:00:00+00:00'));

        self::assertSame('query_1', $repository->nextQueuedColdQuery()?->jobId);

        $completed = $this->coldQuery(
            'query_1',
            status: 'completed',
            rowCount: 2,
            resultObjectKey: 's3://archive/results/query_1.json',
            scannedObjectKeys: ['s3://archive/a.parquet', 's3://archive/b.parquet'],
            completedAt: '2026-06-09T08:30:00+00:00',
        );
        $repository->saveColdQuery($completed);
        self::assertSame(
            '2026-06-09 08:30:00',
            $connection->fetchOne('SELECT completed_at FROM archive_cold_query_jobs WHERE job_id = ?', ['query_1']),
        );

        $fresh = new DatabaseArchiveRepository($connection);
        $stored = $fresh->findColdQuery('query_1');
        self::assertNotNull($stored);
        self::assertSame('completed', $stored->status);
        self::assertSame(['slot-1'], $stored->parameters);
        self::assertSame(['s3://archive/a.parquet', 's3://archive/b.parquet'], $stored->scannedObjectKeys);
        self::assertNull($stored->errorMessage);
        self::assertSame('query_2', $fresh->nextQueuedColdQuery()?->jobId);
        self::assertNull($fresh->findColdQuery('missing'));

        $failed = $this->coldQuery(
            'query_2',
            status: 'failed',
            scannedObjectKeys: ['s3://archive/a.parquet'],
            completedAt: '2026-06-09T08:45:00+00:00',
            errorMessage: 'duckdb fixture rejected query',
        );
        $repository->saveColdQuery($failed);

        self::assertSame('failed', $fresh->findColdQuery('query_2')?->status);
        self::assertSame('duckdb fixture rejected query', $fresh->findColdQuery('query_2')?->errorMessage);
        self::assertNull($fresh->nextQueuedColdQuery());
    }

    public function testNextQueuedColdQueryReturnsNullWhenQueueIsEmpty(): void
    {
        self::assertNull((new DatabaseArchiveRepository($this->createConnection()))->nextQueuedColdQuery());
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE raw_events (event_uuid VARCHAR(64), event_type VARCHAR(64), occurred_at VARCHAR(32), payload_json TEXT, processed_at VARCHAR(32) NULL)');
        $connection->executeStatement('CREATE TABLE archive_manifests (manifest_id VARCHAR(80) PRIMARY KEY, status VARCHAR(32), format VARCHAR(32), event_count INTEGER, partitions_json TEXT, created_at VARCHAR(32))');
        $connection->executeStatement('CREATE TABLE archive_cold_query_jobs (job_id VARCHAR(80) PRIMARY KEY, status VARCHAR(32), sql_text TEXT, parameters_json TEXT, requested_by VARCHAR(160), result_format VARCHAR(32), row_count INTEGER, result_object_key VARCHAR(1024) NULL, scanned_object_keys_json TEXT, error_message TEXT NULL, created_at VARCHAR(32), completed_at VARCHAR(32) NULL)');

        return $connection;
    }

    /** @param array<string, mixed> $payload */
    private function insertRawEvent(Connection $connection, string $eventId, string $eventType, string $occurredAt, array $payload, bool $processed = false): void
    {
        $connection->insert('raw_events', [
            'event_uuid' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processed_at' => $processed ? '2026-06-09 00:00:00' : null,
        ]);
    }

    private function coldQuery(
        string $jobId,
        string $status = 'queued',
        int $rowCount = 0,
        ?string $resultObjectKey = null,
        array $scannedObjectKeys = [],
        string $createdAt = '2026-06-09T08:00:00+00:00',
        ?string $completedAt = null,
        ?string $errorMessage = null,
    ): ColdQueryJob {
        return new ColdQueryJob(
            jobId: $jobId,
            status: $status,
            sql: 'select * from archive where slot_id = ?',
            parameters: ['slot-1'],
            requestedBy: 'ops@example.test',
            resultFormat: 'json',
            rowCount: $rowCount,
            resultObjectKey: $resultObjectKey,
            scannedObjectKeys: $scannedObjectKeys,
            createdAt: new DateTimeImmutable($createdAt, new DateTimeZone('UTC')),
            completedAt: $completedAt === null ? null : new DateTimeImmutable($completedAt, new DateTimeZone('UTC')),
            errorMessage: $errorMessage,
        );
    }
}
