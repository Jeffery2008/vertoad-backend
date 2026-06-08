<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

final readonly class CreativeAsset
{
    public function __construct(
        public ?int $id,
        public int $uploadIntentId,
        public int $organizationId,
        public int $uploaderUserId,
        public AssetType $type,
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?float $durationSeconds,
        public ?string $checksum,
        public AssetStatus $status,
    ) {
    }
}
