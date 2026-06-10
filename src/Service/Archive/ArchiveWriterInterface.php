<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use VertoAD\Domain\Archive\ArchiveEvent;

interface ArchiveWriterInterface
{
    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    public function writePartition(string $partition, string $objectKey, array $events): ArchivePartitionWriteManifest;
}
