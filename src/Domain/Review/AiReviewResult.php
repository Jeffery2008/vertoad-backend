<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

final readonly class AiReviewResult
{
    /**
     * @param list<string> $riskLabels
     * @param list<string> $reasons
     * @param array<string, mixed>|null $raw
     */
    public function __construct(
        public string $provider,
        public string $model,
        public float $riskScore,
        public array $riskLabels,
        public array $reasons,
        public ?array $raw = null,
    ) {
    }
}
