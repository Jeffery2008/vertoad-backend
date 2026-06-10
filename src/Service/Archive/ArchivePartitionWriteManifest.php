<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class ArchivePartitionWriteManifest
{
    public function __construct(
        public string $objectKey,
        public string $checksum,
        public int $byteCount,
        public int $rowCount,
    ) {
    }
}
