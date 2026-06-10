<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use VertoAD\Domain\Archive\ArchiveEvent;

final readonly class DeterministicArchiveWriter implements ArchiveWriterInterface
{
    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    public function writePartition(string $partition, string $objectKey, array $events): ArchivePartitionWriteManifest
    {
        $rows = array_map(
            static fn (ArchiveEvent $event): array => [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                'payload' => $event->payload,
            ],
            $events,
        );
        $payload = json_encode([
            'format' => 'fake-parquet-v1',
            'partition' => $partition,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new ArchivePartitionWriteManifest(
            objectKey: $objectKey,
            checksum: 'sha256:' . hash('sha256', $payload),
            byteCount: strlen($payload),
            rowCount: count($events),
        );
    }
}
