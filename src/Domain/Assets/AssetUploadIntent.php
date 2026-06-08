<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

use DateTimeImmutable;
use VertoAD\Infrastructure\Storage\PresignedUpload;

final readonly class AssetUploadIntent
{
    public function __construct(
        public ?int $id,
        public int $organizationId,
        public int $uploaderUserId,
        public AssetType $type,
        public string $originalFilename,
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public AssetStatus $status,
        public DateTimeImmutable $expiresAt,
        public ?PresignedUpload $upload = null,
    ) {
    }
}
