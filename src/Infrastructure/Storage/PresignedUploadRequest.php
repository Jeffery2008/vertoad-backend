<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Assets\AssetObjectKey;

final readonly class PresignedUploadRequest
{
    public function __construct(
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public DateTimeImmutable $expiresAt,
        /**
         * Asset uploads are intentionally scoped to the mutable staging
         * namespace. Other presigned flows, such as withdrawal proofs, use
         * their own key contract and leave this disabled.
         */
        public bool $stagingOnly = false,
    ) {
        new AssetObjectKey($this->objectKey);
        if ($this->contentType !== strtolower(trim($this->contentType)) || preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $this->contentType) !== 1) {
            throw new InvalidArgumentException('Presigned upload content type must be a normalized MIME type.');
        }
        if ($this->byteSize <= 0) {
            throw new InvalidArgumentException('Presigned upload byte size must be positive.');
        }
    }
}
