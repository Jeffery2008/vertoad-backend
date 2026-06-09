<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\ReviewValidationException;

final readonly class AiReviewQueueJob implements CronJobInterface
{
    public function __construct(
        private ReviewRepositoryInterface $reviews,
        private CreativeReviewProviderInterface $provider,
        private int $batchSize,
    ) {
        if ($this->batchSize <= 0) {
            throw new \InvalidArgumentException('AI review queue batch size must be positive.');
        }
    }

    public function name(): string
    {
        return 'ai-review-queue';
    }

    public function run(): CronJobResult
    {
        $leased = $this->reviews->leasePendingAiReviews($this->batchSize);
        $processed = 0;
        $skipped = 0;

        foreach ($leased as $review) {
            if ($this->process($review)) {
                ++$processed;
            } else {
                ++$skipped;
            }
        }

        return CronJobResult::completed($this->name(), [
            'leased' => count($leased),
            'processed' => $processed,
            'skipped' => $skipped,
        ], 'AI review queue processed pending creative reviews.');
    }

    private function process(CreativeReview $review): bool
    {
        $asset = $this->reviews->findAssetForReview($review);
        if ($asset === null || $asset->status !== 'pending_review') {
            return false;
        }

        try {
            $reviewing = $this->reviews->updateStatus(
                (int) $review->id,
                CreativeReviewStatus::PendingAi,
                CreativeReviewStatus::AiReviewing,
            );
        } catch (ReviewValidationException) {
            return false;
        }

        $this->reviews->recordAiResult((int) $reviewing->id, $this->provider->review(new AiReviewInput(
            assetId: $asset->id,
            assetType: $asset->type,
            objectKey: $asset->objectKey,
            contentType: $asset->contentType,
            landingUrl: null,
            copy: null,
        )));

        return true;
    }
}
