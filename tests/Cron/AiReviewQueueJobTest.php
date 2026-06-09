<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewAsset;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Service\Cron\AiReviewQueueJob;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Tests\Review\ReviewSchema;

final class AiReviewQueueJobTest extends TestCase
{
    public function testProcessesPendingAiReviewsAndRecordsProviderResult(): void
    {
        $connection = $this->createConnection();
        $this->insertAsset($connection, 1, 99, 'pending_review', 'assets/banner.webp');
        $connection->insert('creative_reviews', [
            'id' => 10,
            'asset_id' => 1,
            'organization_id' => 99,
            'status' => 'pending_ai',
            'ai_risk_labels' => '[]',
            'ai_reasons' => '[]',
            'requested_by_user_id' => 7,
        ]);
        $provider = new CapturingCreativeReviewProvider();
        $job = new AiReviewQueueJob(new ReviewRepository($connection), $provider, 10);

        $result = $job->run();

        self::assertSame('ai-review-queue', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->metrics['leased'] ?? null);
        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame(0, $result->metrics['skipped'] ?? null);
        self::assertSame('AI review queue processed pending creative reviews.', $result->message);
        self::assertCount(1, $provider->inputs);
        self::assertSame(1, $provider->inputs[0]->assetId);
        self::assertSame('image', $provider->inputs[0]->assetType);
        self::assertSame('assets/banner.webp', $provider->inputs[0]->objectKey);
        self::assertSame('image/webp', $provider->inputs[0]->contentType);

        $review = (new ReviewRepository($connection))->find(10, 99);
        self::assertNotNull($review);
        self::assertSame('assets/banner.webp', (new ReviewRepository($connection))->findAssetForReview($review)?->objectKey);

        $row = $connection->fetchAssociative('SELECT * FROM creative_reviews WHERE id = 10');
        self::assertIsArray($row);
        self::assertSame('needs_human', $row['status']);
        self::assertSame('unit-ai-provider', $row['ai_provider']);
        self::assertSame('unit-ai-model', $row['ai_model']);
        self::assertSame(0.42, (float) $row['ai_risk_score']);
        self::assertSame('["brand_safety"]', $row['ai_risk_labels']);
        self::assertSame('["Needs human review."]', $row['ai_reasons']);
    }

    public function testHonorsBatchSizeAndSkipsRowsThatCannotBeProcessed(): void
    {
        $connection = $this->createConnection();
        $this->insertAsset($connection, 1, 99, 'pending_review', 'assets/one.webp');
        $this->insertAsset($connection, 2, 99, 'draft', 'assets/not-reviewable.webp');
        $this->insertAsset($connection, 3, 99, 'pending_review', 'assets/left-for-next-run.webp');
        foreach ([
            [10, 1, 'pending_ai'],
            [11, 2, 'pending_ai'],
            [12, 3, 'pending_ai'],
        ] as [$reviewId, $assetId, $status]) {
            $connection->insert('creative_reviews', [
                'id' => $reviewId,
                'asset_id' => $assetId,
                'organization_id' => 99,
                'status' => $status,
                'ai_risk_labels' => '[]',
                'ai_reasons' => '[]',
                'requested_by_user_id' => 7,
            ]);
        }
        $job = new AiReviewQueueJob(new ReviewRepository($connection), new CapturingCreativeReviewProvider(), 2);

        $result = $job->run();

        self::assertSame(2, $result->metrics['leased'] ?? null);
        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame(1, $result->metrics['skipped'] ?? null);
        self::assertSame('needs_human', $connection->fetchOne('SELECT status FROM creative_reviews WHERE id = 10'));
        self::assertSame('pending_ai', $connection->fetchOne('SELECT status FROM creative_reviews WHERE id = 11'));
        self::assertSame('pending_ai', $connection->fetchOne('SELECT status FROM creative_reviews WHERE id = 12'));
    }

    public function testRejectsInvalidBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI review queue batch size must be positive.');

        new AiReviewQueueJob(new ReviewRepository($this->createConnection()), new CapturingCreativeReviewProvider(), 0);
    }

    public function testRepositoryRejectsInvalidAiReviewLeaseLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI review queue batch size must be positive.');

        (new ReviewRepository($this->createConnection()))->leasePendingAiReviews(0);
    }

    public function testSkipsReviewWhenConcurrentStatusChangePreventsClaimingIt(): void
    {
        $job = new AiReviewQueueJob(new RacingReviewRepository(), new CapturingCreativeReviewProvider(), 1);

        $result = $job->run();

        self::assertSame(1, $result->metrics['leased'] ?? null);
        self::assertSame(0, $result->metrics['processed'] ?? null);
        self::assertSame(1, $result->metrics['skipped'] ?? null);
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        ReviewSchema::create($connection);

        return $connection;
    }

    private function insertAsset(Connection $connection, int $id, int $organizationId, string $status, string $objectKey): void
    {
        $connection->insert('asset_upload_intents', [
            'id' => $id,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => basename($objectKey),
            'object_key' => $objectKey,
            'content_type' => 'image/webp',
            'byte_size' => 1200,
            'status' => 'confirmed',
            'expires_at' => '2026-06-10 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $id,
            'upload_intent_id' => $id,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => $objectKey,
            'content_type' => 'image/webp',
            'byte_size' => 1200,
            'width' => 300,
            'height' => 250,
            'status' => $status,
        ]);
    }
}

final class CapturingCreativeReviewProvider implements CreativeReviewProviderInterface
{
    /** @var list<AiReviewInput> */
    public array $inputs = [];

    public function review(AiReviewInput $input): AiReviewResult
    {
        $this->inputs[] = $input;

        return new AiReviewResult(
            provider: 'unit-ai-provider',
            model: 'unit-ai-model',
            riskScore: 0.42,
            riskLabels: ['brand_safety'],
            reasons: ['Needs human review.'],
            raw: ['id' => 'review-1'],
        );
    }
}

final class RacingReviewRepository implements ReviewRepositoryInterface
{
    public function findAsset(int $assetId, int $organizationId): ?CreativeReviewAsset
    {
        return $this->findAssetForReview($this->review());
    }

    public function findByAsset(int $assetId, int $organizationId): ?CreativeReview
    {
        return null;
    }

    public function find(int $reviewId, int $organizationId): ?CreativeReview
    {
        return $this->review();
    }

    public function leasePendingAiReviews(int $limit): array
    {
        return [$this->review()];
    }

    public function findAssetForReview(CreativeReview $review): ?CreativeReviewAsset
    {
        return new CreativeReviewAsset(
            id: $review->assetId,
            organizationId: $review->organizationId,
            type: 'image',
            objectKey: 'assets/race.webp',
            contentType: 'image/webp',
            status: 'pending_review',
        );
    }

    public function create(CreativeReview $review): CreativeReview
    {
        return $review;
    }

    public function updateStatus(int $reviewId, CreativeReviewStatus $from, CreativeReviewStatus $to): CreativeReview
    {
        throw new \VertoAD\Service\Review\ReviewValidationException(
            'review_transition_invalid',
            'Review status transition is invalid.',
            409,
        );
    }

    public function recordAiResult(int $reviewId, AiReviewResult $result): CreativeReview
    {
        return $this->review();
    }

    public function recordHumanDecision(int $reviewId, int $actorUserId, string $decision, ?string $reason): CreativeReview
    {
        return $this->review();
    }

    private function review(): CreativeReview
    {
        return new CreativeReview(
            id: 10,
            assetId: 1,
            organizationId: 99,
            status: CreativeReviewStatus::PendingAi,
            aiProvider: null,
            aiModel: null,
            aiRiskScore: null,
            aiRiskLabels: [],
            aiReasons: [],
            aiRawResult: null,
            requestedByUserId: 7,
            finalDecision: null,
            finalDecisionReason: null,
            finalDecidedByUserId: null,
            finalDecidedAt: null,
        );
    }
}
