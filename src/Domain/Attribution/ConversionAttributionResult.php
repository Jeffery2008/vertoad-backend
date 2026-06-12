<?php

declare(strict_types=1);

namespace VertoAD\Domain\Attribution;

final readonly class ConversionAttributionResult
{
    public function __construct(
        public string $conversionId,
        public ?int $organizationId,
        public ?int $oauthClientId,
        public ?int $recordedByUserId,
        public bool $attributed,
        public bool $duplicate,
        public ?string $clickEventId,
        public ?string $decisionId,
        public ?int $campaignId,
        public int $windowSeconds,
        public string $source,
        public string $conversionName,
        public int $valuePoints,
    ) {
    }
}
