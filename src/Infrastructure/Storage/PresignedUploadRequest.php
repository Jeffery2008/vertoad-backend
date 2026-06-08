<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use DateTimeImmutable;

final readonly class PresignedUploadRequest
{
    public function __construct(
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
