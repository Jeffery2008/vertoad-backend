<?php

declare(strict_types=1);

namespace VertoAD\Domain\Archive;

use DateTimeImmutable;

final readonly class ArchiveManifest
{
    /**
     * @param list<array{
     *     partition:string,
     *     object_key:string,
     *     event_count:int,
     *     checksum?:string,
     *     byte_count?:int,
     *     row_count?:int
     * }> $partitions
     */
    public function __construct(
        public string $manifestId,
        public string $status,
        public string $format,
        public int $eventCount,
        public array $partitions,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
