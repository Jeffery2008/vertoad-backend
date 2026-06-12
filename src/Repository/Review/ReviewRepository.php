<?php

declare(strict_types=1);

namespace VertoAD\Repository\Review;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewAsset;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Service\Review\ReviewValidationException;

final class ReviewRepository implements ReviewRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findAsset(int $assetId, int $organizationId): ?CreativeReviewAsset
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'organization_id', 'type', 'object_key', 'content_type', 'status')
            ->from('creative_assets')
            ->where('id = :id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('id', $assetId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : new CreativeReviewAsset(
            id: (int) $row['id'],
            organizationId: (int) $row['organization_id'],
            type: (string) $row['type'],
            objectKey: (string) $row['object_key'],
            contentType: (string) $row['content_type'],
            status: (string) $row['status'],
        );
    }

    public function findByAsset(int $assetId, int $organizationId): ?CreativeReview
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('creative_reviews')
            ->where('asset_id = :asset_id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('asset_id', $assetId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function find(int $reviewId, int $organizationId): ?CreativeReview
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('creative_reviews')
            ->where('id = :id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('id', $reviewId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForReviewQueue(int $organizationId, CreativeReviewStatus $status, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Review queue list limit must be positive.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('creative_reviews')
            ->where('organization_id = :organization_id')
            ->andWhere('status = :status')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('organization_id', $organizationId)
            ->setParameter('status', $status->value)
            ->fetchAllAssociative();

        return array_map(fn (array $row): CreativeReview => $this->hydrate($row), $rows);
    }

    public function leasePendingAiReviews(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('AI review queue batch size must be positive.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('creative_reviews')
            ->where('status = :status')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('status', CreativeReviewStatus::PendingAi->value)
            ->fetchAllAssociative();

        return array_map(fn (array $row): CreativeReview => $this->hydrate($row), $rows);
    }

    public function findAssetForReview(CreativeReview $review): ?CreativeReviewAsset
    {
        return $this->findAsset($review->assetId, $review->organizationId);
    }

    public function create(CreativeReview $review): CreativeReview
    {
        $this->connection->insert('creative_reviews', [
            'asset_id' => $review->assetId,
            'organization_id' => $review->organizationId,
            'status' => $review->status->value,
            'ai_risk_labels' => '[]',
            'ai_reasons' => '[]',
            'requested_by_user_id' => $review->requestedByUserId,
        ]);

        return $this->find((int) $this->connection->lastInsertId(), $review->organizationId)
            ?? throw new ReviewValidationException('review_persistence_failed', 'Review could not be created.', 500);
    }

    public function updateStatus(int $reviewId, CreativeReviewStatus $from, CreativeReviewStatus $to): CreativeReview
    {
        $affected = $this->connection->update(
            'creative_reviews',
            ['status' => $to->value, 'updated_at' => $this->now()],
            ['id' => $reviewId, 'status' => $from->value],
        );
        if ($affected !== 1) {
            throw new ReviewValidationException('review_transition_invalid', 'Review status transition is invalid.', 409);
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM creative_reviews WHERE id = ?', [$reviewId]);

        return is_array($row) ? $this->hydrate($row) : throw new ReviewValidationException('review_not_found', 'Review was not found.', 404);
    }

    public function recordAiResult(int $reviewId, AiReviewResult $result): CreativeReview
    {
        $this->connection->update('creative_reviews', [
            'status' => CreativeReviewStatus::NeedsHuman->value,
            'ai_provider' => $result->provider,
            'ai_model' => $result->model,
            'ai_risk_score' => $result->riskScore,
            'ai_risk_labels' => json_encode($result->riskLabels, JSON_THROW_ON_ERROR),
            'ai_reasons' => json_encode($result->reasons, JSON_THROW_ON_ERROR),
            'ai_raw_result' => json_encode($result->raw, JSON_THROW_ON_ERROR),
            'updated_at' => $this->now(),
        ], ['id' => $reviewId]);

        $row = $this->connection->fetchAssociative('SELECT * FROM creative_reviews WHERE id = ?', [$reviewId]);

        return is_array($row) ? $this->hydrate($row) : throw new ReviewValidationException('review_not_found', 'Review was not found.', 404);
    }

    public function recordHumanDecision(int $reviewId, int $actorUserId, string $decision, ?string $reason): CreativeReview
    {
        return $this->connection->transactional(function () use ($reviewId, $actorUserId, $decision, $reason): CreativeReview {
            $review = $this->reviewById($reviewId);
            $toStatus = $decision === 'approved' ? CreativeReviewStatus::Approved : CreativeReviewStatus::Rejected;
            $eligibility = $decision === 'approved' ? 'eligible' : 'ineligible';
            $decidedAt = $this->now();

            $this->connection->update('creative_reviews', [
                'status' => $toStatus->value,
                'final_decision' => $decision,
                'final_decision_reason' => $reason,
                'final_decided_by_user_id' => $actorUserId,
                'final_decided_at' => $decidedAt,
                'updated_at' => $decidedAt,
            ], ['id' => $reviewId]);

            $this->connection->insert('review_decisions', [
                'review_id' => $reviewId,
                'asset_id' => $review->assetId,
                'organization_id' => $review->organizationId,
                'actor_user_id' => $actorUserId,
                'decision' => $decision,
                'reason' => $reason,
                'from_status' => $review->status->value,
                'to_status' => $toStatus->value,
            ]);
            $this->connection->insert('review_eligibility_events', [
                'review_id' => $reviewId,
                'asset_id' => $review->assetId,
                'organization_id' => $review->organizationId,
                'eligibility' => $eligibility,
            ]);

            return $this->reviewById($reviewId);
        });
    }

    private function reviewById(int $reviewId): CreativeReview
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM creative_reviews WHERE id = ?', [$reviewId]);

        return is_array($row) ? $this->hydrate($row) : throw new ReviewValidationException('review_not_found', 'Review was not found.', 404);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CreativeReview
    {
        return new CreativeReview(
            id: (int) $row['id'],
            assetId: (int) $row['asset_id'],
            organizationId: (int) $row['organization_id'],
            status: CreativeReviewStatus::from((string) $row['status']),
            aiProvider: $row['ai_provider'] === null ? null : (string) $row['ai_provider'],
            aiModel: $row['ai_model'] === null ? null : (string) $row['ai_model'],
            aiRiskScore: $row['ai_risk_score'] === null ? null : (float) $row['ai_risk_score'],
            aiRiskLabels: $this->decodeStringList((string) $row['ai_risk_labels']),
            aiReasons: $this->decodeStringList((string) $row['ai_reasons']),
            aiRawResult: $row['ai_raw_result'] === null ? null : $this->decodeArray((string) $row['ai_raw_result']),
            requestedByUserId: (int) $row['requested_by_user_id'],
            finalDecision: $row['final_decision'] === null ? null : (string) $row['final_decision'],
            finalDecisionReason: $row['final_decision_reason'] === null ? null : (string) $row['final_decision_reason'],
            finalDecidedByUserId: $row['final_decided_by_user_id'] === null ? null : (int) $row['final_decided_by_user_id'],
            finalDecidedAt: $row['final_decided_at'] === null ? null : (string) $row['final_decided_at'],
        );
    }

    /** @return list<string> */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value)));
    }

    /** @return array<string, mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
