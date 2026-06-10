<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchivePartitionWriteManifest;
use VertoAD\Service\Archive\ArchiveWriterInterface;
use VertoAD\Service\Archive\DeterministicArchiveWriter;

final class ArchiveJobTest extends TestCase
{
    public function testParquetArchiveJobCreatesManifestPartitionedByEventTypeDateAndHour(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
            new ArchiveEvent('click', 'clk-1', new DateTimeImmutable('2026-06-08T10:30:00+00:00'), ['campaign_id' => 123]),
            new ArchiveEvent('impression', 'imp-2', new DateTimeImmutable('2026-06-08T11:05:00+00:00'), ['campaign_id' => 124]),
        ]);
        $writer = new RecordingArchiveWriter(new DeterministicArchiveWriter());
        $job = new ArchiveJob($repository, 's3://vertoad-archive/raw-events', $writer);

        $result = $job->run();
        $manifest = $repository->findManifest((string) ($result->metrics['manifest_id'] ?? ''));

        self::assertSame('parquet-archive', $result->jobName);
        self::assertSame('completed', $result->status);
        self::assertSame(3, $result->metrics['events_archived'] ?? null);
        self::assertSame(3, $result->metrics['partitions'] ?? null);
        self::assertNotNull($manifest);
        self::assertSame('completed', $manifest->status);
        self::assertSame('parquet', $manifest->format);
        self::assertSame(3, $manifest->eventCount);
        self::assertSame([
            'event_type=click/date=2026-06-08/hour=10',
            'event_type=impression/date=2026-06-08/hour=10',
            'event_type=impression/date=2026-06-08/hour=11',
        ], array_map(static fn (array $partition): string => $partition['partition'], $manifest->partitions));
        self::assertSame(
            's3://vertoad-archive/raw-events/event_type=click/date=2026-06-08/hour=10/part-20260608T101500Z-20260608T110500Z.parquet',
            $manifest->partitions[0]['object_key'],
        );
        self::assertSame(1, $manifest->partitions[0]['row_count']);
        self::assertIsInt($manifest->partitions[0]['byte_count']);
        self::assertGreaterThan(0, $manifest->partitions[0]['byte_count']);
        self::assertStringStartsWith('sha256:', $manifest->partitions[0]['checksum']);
        self::assertSame([
            'event_type=click/date=2026-06-08/hour=10',
            'event_type=impression/date=2026-06-08/hour=10',
            'event_type=impression/date=2026-06-08/hour=11',
        ], $writer->partitionsWritten);

        $repeat = $job->run();

        self::assertSame(0, $repeat->metrics['events_archived'] ?? null);
        self::assertSame([], $repository->pendingEvents());
    }

    public function testArchiveJobDoesNotMarkEventsArchivedWhenWriterFails(): void
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        $job = new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new FailingArchiveWriter());

        try {
            $job->run();
            self::fail('Archive writer failures should bubble without marking events archived.');
        } catch (\RuntimeException $exception) {
            self::assertSame('archive writer unavailable', $exception->getMessage());
        }

        self::assertSame(['imp-1'], array_map(static fn (ArchiveEvent $event): string => $event->eventId, $repository->pendingEvents()));
        self::assertSame([], $repository->manifests());
    }
}

final class RecordingArchiveWriter implements ArchiveWriterInterface
{
    /** @var list<string> */
    public array $partitionsWritten = [];

    public function __construct(private readonly ArchiveWriterInterface $inner)
    {
    }

    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    public function writePartition(string $partition, string $objectKey, array $events): ArchivePartitionWriteManifest
    {
        $this->partitionsWritten[] = $partition;

        return $this->inner->writePartition($partition, $objectKey, $events);
    }
}

final class FailingArchiveWriter implements ArchiveWriterInterface
{
    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    public function writePartition(string $partition, string $objectKey, array $events): ArchivePartitionWriteManifest
    {
        throw new \RuntimeException('archive writer unavailable');
    }
}
