<?php

declare(strict_types=1);

namespace VertoAD\Service;

use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\ReviewValidationException;

final readonly class ReviewService
{
    public function __construct(
        private ReviewRepositoryInterface $repository,
        private CreativeReviewProviderInterface $provider,
    ) {
    }

    public function requestAiReview(
        int $organizationId,
        int $requestedByUserId,
        int $assetId,
        ?string $landingUrl = null,
        ?string $copy = null,
    ): CreativeReview {
        $asset = $this->repository->findAsset($assetId, $organizationId);
        if ($asset === null || $asset->status !== 'pending_review') {
            throw new ReviewValidationException('review_asset_not_found', 'Reviewable asset was not found.', 404);
        }

        if ($this->repository->findByAsset($assetId, $organizationId) !== null) {
            throw new ReviewValidationException('review_transition_invalid', 'AI review has already been requested for this asset.', 409);
        }

        $review = $this->repository->create(new CreativeReview(
            id: null,
            assetId: $asset->id,
            organizationId: $organizationId,
            status: CreativeReviewStatus::Draft,
            aiProvider: null,
            aiModel: null,
            aiRiskScore: null,
            aiRiskLabels: [],
            aiReasons: [],
            aiRawResult: null,
            requestedByUserId: $requestedByUserId,
            finalDecision: null,
            finalDecisionReason: null,
            finalDecidedByUserId: null,
            finalDecidedAt: null,
        ));

        $review = $this->repository->updateStatus((int) $review->id, CreativeReviewStatus::Draft, CreativeReviewStatus::PendingAi);
        $review = $this->repository->updateStatus((int) $review->id, CreativeReviewStatus::PendingAi, CreativeReviewStatus::AiReviewing);

        return $this->recordAiResult($organizationId, (int) $review->id, $this->provider->review(new AiReviewInput(
            assetId: $asset->id,
            assetType: $asset->type,
            objectKey: $asset->objectKey,
            contentType: $asset->contentType,
            landingUrl: $this->optionalString($landingUrl),
            copy: $this->optionalString($copy),
        )));
    }

    public function recordAiResult(int $organizationId, int $reviewId, AiReviewResult $result): CreativeReview
    {
        $review = $this->find($organizationId, $reviewId);
        if ($review->status !== CreativeReviewStatus::AiReviewing) {
            throw new ReviewValidationException('review_transition_invalid', 'AI result can only be recorded for an AI-reviewing review.', 409);
        }

        return $this->repository->recordAiResult($reviewId, $result);
    }

    public function getStatus(int $organizationId, int $reviewId): CreativeReview
    {
        return $this->find($organizationId, $reviewId);
    }

    /** @return list<CreativeReview> */
    public function listQueue(int $organizationId, string $status, int $limit): array
    {
        if ($status !== CreativeReviewStatus::NeedsHuman->value) {
            throw new ReviewValidationException(
                'invalid_request',
                'status must be needs_human.',
            );
        }

        if ($limit <= 0 || $limit > 100) {
            throw new ReviewValidationException(
                'invalid_request',
                'limit must be a positive integer no greater than 100.',
            );
        }

        return $this->repository->listForReviewQueue($organizationId, CreativeReviewStatus::NeedsHuman, $limit);
    }

    public function approve(int $organizationId, int $actorUserId, int $reviewId, ?string $reason): CreativeReview
    {
        return $this->decide($organizationId, $actorUserId, $reviewId, 'approved', $reason);
    }

    public function reject(int $organizationId, int $actorUserId, int $reviewId, ?string $reason): CreativeReview
    {
        return $this->decide($organizationId, $actorUserId, $reviewId, 'rejected', $reason);
    }

    private function decide(int $organizationId, int $actorUserId, int $reviewId, string $decision, ?string $reason): CreativeReview
    {
        $review = $this->find($organizationId, $reviewId);
        if ($review->status !== CreativeReviewStatus::NeedsHuman) {
            throw new ReviewValidationException('review_transition_invalid', 'Human decision requires a review awaiting human approval.', 409);
        }

        return $this->repository->recordHumanDecision($reviewId, $actorUserId, $decision, $this->optionalString($reason));
    }

    private function find(int $organizationId, int $reviewId): CreativeReview
    {
        return $this->repository->find($reviewId, $organizationId)
            ?? throw new ReviewValidationException('review_not_found', 'Review was not found.', 404);
    }

    private function optionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
