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
    ) {
    }

    public function run(): ArchiveJobResult
    {
        $events = $this->repository->pendingEvents();
        $eventIds = [];
        $partitions = [];
        $first = null;
        $last = null;

        foreach ($events as $event) {
            $eventIds[] = $event->eventId;
            $first = $first === null || $event->occurredAt < $first ? $event->occurredAt : $first;
            $last = $last === null || $event->occurredAt > $last ? $event->occurredAt : $last;
            $partition = $this->partition($event->eventType, $event->occurredAt);
            $partitions[$partition] ??= ['partition' => $partition, 'object_key' => '', 'event_count' => 0];
            ++$partitions[$partition]['event_count'];
        }

        ksort($partitions);
        $objectSuffix = 'part-' . ($first ?? new DateTimeImmutable())->format('Ymd\THis\Z')
            . '-' . ($last ?? new DateTimeImmutable())->format('Ymd\THis\Z') . '.parquet';

        foreach ($partitions as $partition => $metadata) {
            $partitions[$partition]['object_key'] = rtrim($this->baseObjectKey, '/') . '/' . $partition . '/' . $objectSuffix;
        }

        $manifestId = 'manifest_' . sha1(implode('|', array_keys($partitions)) . '|' . count($events));
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
}
