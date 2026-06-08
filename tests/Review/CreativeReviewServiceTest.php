<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;
use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReviewStatus;

final class CreativeReviewServiceTest extends TestCase
{
    public function testAiReviewResultCannotMakeAssetDeliverableWithoutHumanDecision(): void
    {
        $connection = $this->connectionWithAsset();
        $service = $this->service($connection);

        $review = $service->requestAiReview(99, 7, 1, 'https://landing.example', 'Buy now');
        self::assertSame('needs_human', $review->status->value);
        self::assertSame(['brand_safety'], $review->aiRiskLabels);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM review_eligibility_events'));

        $assetStatus = (string) $connection->fetchOne('SELECT status FROM creative_assets WHERE id = 1');
        self::assertSame('pending_review', $assetStatus);
    }

    public function testHumanApprovalAndRejectionAreAuditedAndEmitEligibilityEvents(): void
    {
        $connection = $this->connectionWithAsset();
        $service = $this->service($connection);
        $review = $service->requestAiReview(99, 7, 1);

        $approved = $service->approve(99, 8, (int) $review->id, 'Policy checked');
        self::assertSame('approved', $approved->status->value);
        self::assertSame('approved', $approved->finalDecision);
        self::assertSame(8, $approved->finalDecidedByUserId);
        self::assertSame('eligible', (string) $connection->fetchOne('SELECT eligibility FROM review_eligibility_events'));
        self::assertSame('approved', (string) $connection->fetchOne('SELECT decision FROM review_decisions'));

        $connection = $this->connectionWithAsset(2);
        $service = $this->service($connection);
        $second = $service->requestAiReview(99, 7, 2);
        $rejected = $service->reject(99, 8, (int) $second->id, 'Unsafe claim');
        self::assertSame('rejected', $rejected->status->value);
        self::assertSame('ineligible', (string) $connection->fetchOne('SELECT eligibility FROM review_eligibility_events'));
    }

    public function testInvalidTransitionsAndOwnershipFailWithStableErrors(): void
    {
        $connection = $this->connectionWithAsset();
        $service = $this->service($connection);

        $missing = $this->capture(fn () => $service->requestAiReview(100, 7, 1));
        self::assertSame('review_asset_not_found', $missing->errorCode);
        self::assertSame(404, $missing->status);

        $review = $service->requestAiReview(99, 7, 1);
        $duplicate = $this->capture(fn () => $service->requestAiReview(99, 7, 1));
        self::assertSame('review_transition_invalid', $duplicate->errorCode);

        $approved = $service->approve(99, 8, (int) $review->id, null);
        $lateReject = $this->capture(fn () => $service->reject(99, 8, (int) $approved->id, 'Changed mind'));
        self::assertSame('review_transition_invalid', $lateReject->errorCode);

        $wrongOrg = $this->capture(fn () => $service->approve(100, 8, (int) $approved->id, null));
        self::assertSame('review_not_found', $wrongOrg->errorCode);
    }

    public function testRepositoryAndServiceRejectOutOfOrderAiResultTransitions(): void
    {
        $connection = $this->connectionWithAsset();
        $repository = new ReviewRepository($connection);
        $service = new ReviewService($repository, new DeterministicCreativeReviewProvider());
        $review = $service->requestAiReview(99, 7, 1);

        $outOfOrder = $this->capture(fn () => $service->recordAiResult(99, (int) $review->id, new AiReviewResult(
            provider: 'deterministic',
            model: 'deterministic-v1',
            riskScore: 0.1,
            riskLabels: [],
            reasons: [],
        )));
        self::assertSame('review_transition_invalid', $outOfOrder->errorCode);

        $badRepositoryTransition = $this->capture(fn () => $repository->updateStatus(
            (int) $review->id,
            CreativeReviewStatus::Draft,
            CreativeReviewStatus::AiReviewing,
        ));
        self::assertSame('review_transition_invalid', $badRepositoryTransition->errorCode);
    }

    public function testRepositoryToleratesMalformedJsonAndProviderToleratesMalformedLists(): void
    {
        $connection = $this->connectionWithAsset();
        $service = $this->service($connection);
        $review = $service->requestAiReview(99, 7, 1);
        $connection->update('creative_reviews', [
            'ai_risk_labels' => 'not-json',
            'ai_reasons' => 'not-json',
        ], ['id' => $review->id]);

        $reloaded = (new ReviewRepository($connection))->find((int) $review->id, 99);
        self::assertNotNull($reloaded);
        self::assertSame([], $reloaded->aiRiskLabels);
        self::assertSame([], $reloaded->aiReasons);

        $provider = new DeterministicCreativeReviewProvider([
            'risk_labels' => 'bad',
            'reasons' => 'bad',
        ]);
        $result = $provider->review(new \VertoAD\Domain\Review\AiReviewInput(
            assetId: 1,
            assetType: 'image',
            objectKey: 'organizations/99/assets/creative.png',
            contentType: 'image/png',
            landingUrl: null,
            copy: null,
        ));
        self::assertSame([], $result->riskLabels);
        self::assertSame([], $result->reasons);
    }

    private function capture(callable $callback): ReviewValidationException
    {
        try {
            $callback();
        } catch (ReviewValidationException $exception) {
            return $exception;
        }

        self::fail('Expected review validation exception.');
    }

    private function service(Connection $connection): ReviewService
    {
        return new ReviewService(
            new ReviewRepository($connection),
            new DeterministicCreativeReviewProvider([
                'risk_score' => 0.41,
                'risk_labels' => ['brand_safety'],
                'reasons' => ['Deterministic review requires a human decision.'],
            ]),
        );
    }

    private function connectionWithAsset(int $assetId = 1): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        ReviewSchema::create($connection);
        $connection->insert('asset_upload_intents', [
            'id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative.png',
            'object_key' => 'organizations/99/assets/creative-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'pending_review',
            'expires_at' => '2026-06-08 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $assetId,
            'upload_intent_id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/99/assets/creative-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => 'pending_review',
        ]);

        return $connection;
    }
}
