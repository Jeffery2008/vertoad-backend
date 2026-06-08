<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Review;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Review\CreativeReview;

final class ReviewSerializers
{
    /**
     * @return array<string, mixed>
     */
    public static function review(CreativeReview $review): array
    {
        return [
            'id' => $review->id,
            'asset_id' => $review->assetId,
            'organization_id' => $review->organizationId,
            'status' => $review->status->value,
            'ai' => [
                'provider' => $review->aiProvider,
                'model' => $review->aiModel,
                'risk_score' => $review->aiRiskScore,
                'risk_labels' => $review->aiRiskLabels,
                'reasons' => $review->aiReasons,
                'raw_result' => $review->aiRawResult,
            ],
            'requested_by_user_id' => $review->requestedByUserId,
            'final_decision' => $review->finalDecision,
            'final_decision_reason' => $review->finalDecisionReason,
            'final_decided_by_user_id' => $review->finalDecidedByUserId,
            'final_decided_at' => $review->finalDecidedAt,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
