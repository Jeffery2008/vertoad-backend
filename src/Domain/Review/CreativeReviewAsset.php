<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

final readonly class CreativeReviewAsset
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $type,
        public string $objectKey,
        public string $contentType,
        public string $status,
    ) {
    }
}
