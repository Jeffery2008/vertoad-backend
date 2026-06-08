<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

final readonly class AiReviewInput
{
    public function __construct(
        public int $assetId,
        public string $assetType,
        public string $objectKey,
        public string $contentType,
        public ?string $landingUrl,
        public ?string $copy,
    ) {
    }
}
