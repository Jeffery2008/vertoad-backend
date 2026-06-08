<?php

declare(strict_types=1);

namespace VertoAD\Service\Review;

use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\AiReviewResult;

final readonly class DeterministicCreativeReviewProvider implements CreativeReviewProviderInterface
{
    /** @param array<string, mixed> $result */
    public function __construct(private array $result = [])
    {
    }

    public function review(AiReviewInput $input): AiReviewResult
    {
        $riskLabels = $this->stringList($this->result['risk_labels'] ?? ['manual_review']);
        $reasons = $this->stringList($this->result['reasons'] ?? ['Deterministic provider does not make final decisions.']);

        return new AiReviewResult(
            provider: (string) ($this->result['provider'] ?? 'deterministic'),
            model: (string) ($this->result['model'] ?? 'deterministic-v1'),
            riskScore: (float) ($this->result['risk_score'] ?? 0.0),
            riskLabels: $riskLabels,
            reasons: $reasons,
            raw: [
                'asset_id' => $input->assetId,
                'asset_type' => $input->assetType,
                'object_key' => $input->objectKey,
                'content_type' => $input->contentType,
                'landing_url_present' => $input->landingUrl !== null,
                'copy_present' => $input->copy !== null,
                'risk_labels' => $riskLabels,
                'reasons' => $reasons,
            ],
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
