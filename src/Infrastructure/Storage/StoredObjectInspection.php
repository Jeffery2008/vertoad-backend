<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

final readonly class StoredObjectInspection
{
    public function __construct(
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?float $durationSeconds,
        public ?string $checksum,
        public string $leadingBytes,
        public ?string $body = null,
    ) {
    }
}
