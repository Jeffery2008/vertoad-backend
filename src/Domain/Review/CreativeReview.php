<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

final readonly class CreativeReview
{
    /**
     * @param list<string> $aiRiskLabels
     * @param list<string> $aiReasons
     * @param array<string, mixed>|null $aiRawResult
     */
    public function __construct(
        public ?int $id,
        public int $assetId,
        public int $organizationId,
        public CreativeReviewStatus $status,
        public ?string $aiProvider,
        public ?string $aiModel,
        public ?float $aiRiskScore,
        public array $aiRiskLabels,
        public array $aiReasons,
        public ?array $aiRawResult,
        public int $requestedByUserId,
        public ?string $finalDecision,
        public ?string $finalDecisionReason,
        public ?int $finalDecidedByUserId,
        public ?string $finalDecidedAt,
    ) {
    }
}
