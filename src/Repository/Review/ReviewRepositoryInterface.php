<?php

declare(strict_types=1);

namespace VertoAD\Repository\Review;

use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewAsset;
use VertoAD\Domain\Review\CreativeReviewStatus;

interface ReviewRepositoryInterface
{
    public function findAsset(int $assetId, int $organizationId): ?CreativeReviewAsset;

    public function findByAsset(int $assetId, int $organizationId): ?CreativeReview;

    public function find(int $reviewId, int $organizationId): ?CreativeReview;

    /** @return list<CreativeReview> */
    public function leasePendingAiReviews(int $limit): array;

    public function findAssetForReview(CreativeReview $review): ?CreativeReviewAsset;

    public function create(CreativeReview $review): CreativeReview;

    public function updateStatus(int $reviewId, CreativeReviewStatus $from, CreativeReviewStatus $to): CreativeReview;

    public function recordAiResult(int $reviewId, AiReviewResult $result): CreativeReview;

    public function recordHumanDecision(int $reviewId, int $actorUserId, string $decision, ?string $reason): CreativeReview;
}
