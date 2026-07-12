<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use DateTimeImmutable;
use VertoAD\Domain\Archive\ArchiveJobResult;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;

final readonly class ArchiveJob
{
    public function __construct(
        private ArchiveRepositoryInterface $repository,
        private string $baseObjectKey,
        private ArchiveWriterInterface $writer,
    ) {
    }

    public function run(): ArchiveJobResult
    {
        $events = $this->repository->pendingEvents();
        $batchFingerprint = $this->batchFingerprint($events);
        $eventIds = [];
        $partitions = [];
        $partitionEvents = [];
        $first = null;
        $last = null;

        foreach ($events as $event) {
            $eventIds[] = $event->eventId;
            $first = $first === null || $event->occurredAt < $first ? $event->occurredAt : $first;
            $last = $last === null || $event->occurredAt > $last ? $event->occurredAt : $last;
            $partition = $this->partition($event->eventType, $event->occurredAt);
            $partitions[$partition] ??= ['partition' => $partition, 'object_key' => '', 'event_count' => 0];
            $partitionEvents[$partition] ??= [];
            $partitionEvents[$partition][] = $event;
            ++$partitions[$partition]['event_count'];
        }

        ksort($partitions);
        ksort($partitionEvents);
        $objectSuffix = 'part-' . ($first ?? new DateTimeImmutable())->format('Ymd\THis\Z')
            . '-' . ($last ?? new DateTimeImmutable())->format('Ymd\THis\Z')
            . '-' . substr($batchFingerprint, 0, 16) . '.parquet';

        foreach ($partitions as $partition => $metadata) {
            $objectKey = rtrim($this->baseObjectKey, '/') . '/' . $partition . '/' . $objectSuffix;
            $write = $this->writer->writePartition($partition, $objectKey, $partitionEvents[$partition]);
            $partitions[$partition]['object_key'] = $write->objectKey;
            $partitions[$partition]['checksum'] = $write->checksum;
            $partitions[$partition]['byte_count'] = $write->byteCount;
            $partitions[$partition]['row_count'] = $write->rowCount;
        }

        $manifestId = 'manifest_' . $batchFingerprint;
        $manifest = $this->repository->saveManifest(new ArchiveManifest(
            manifestId: $manifestId,
            status: 'completed',
            format: 'parquet',
            eventCount: count($events),
            partitions: array_values($partitions),
            createdAt: new DateTimeImmutable(),
        ));
        $this->repository->markEventsArchived($eventIds, new DateTimeImmutable());

        return new ArchiveJobResult('parquet-archive', 'completed', [
            'manifest_id' => $manifest->manifestId,
            'events_archived' => $manifest->eventCount,
            'partitions' => count($manifest->partitions),
        ]);
    }

    private function partition(string $eventType, DateTimeImmutable $occurredAt): string
    {
        return 'event_type=' . $eventType . '/date=' . $occurredAt->format('Y-m-d') . '/hour=' . $occurredAt->format('H');
    }

    /**
     * @param list<\VertoAD\Domain\Archive\ArchiveEvent> $events
     */
    private function batchFingerprint(array $events): string
    {
        $identities = array_map(
            static fn ($event): string => json_encode([
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'occurred_at' => $event->occurredAt->format('U.uP'),
                'payload' => $event->payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $events,
        );
        sort($identities, SORT_STRING);

        return hash('sha256', implode("\n", $identities));
    }
}
