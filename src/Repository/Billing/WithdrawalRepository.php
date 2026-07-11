<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use VertoAD\Domain\Billing\WithdrawalPaymentStatus;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalProofStatus;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Domain\Billing\WithdrawalReviewStatus;

final class WithdrawalRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional($operation);
    }

    public function lockOrganizationForUpdate(int $organizationId): void
    {
        if ($organizationId <= 0 || $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $this->connection->executeQuery(
            'SELECT id FROM organizations WHERE id = ? FOR UPDATE',
            [$organizationId],
        )->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $payoutAccount
     */
    public function createRequest(
        int $organizationId,
        int $requestedByUserId,
        int $pointsAmount,
        string $amountCny,
        int $pointsPerCny,
        string $idempotencyKey,
        string $payoutMethod,
        array $payoutAccount,
        ?string $notes,
        int $ledgerEntryId,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $this->connection->insert('withdrawal_requests', [
            'organization_id' => $organizationId,
            'requested_by_user_id' => $requestedByUserId,
            'points_amount' => $pointsAmount,
            'amount_cny' => $amountCny,
            'points_per_cny' => $pointsPerCny,
            'idempotency_key' => $idempotencyKey,
            'review_status' => WithdrawalReviewStatus::Pending->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
            'payout_method' => $payoutMethod,
            'payout_account_json' => json_encode($payoutAccount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'applicant_notes' => $notes,
            'ledger_entry_id' => $ledgerEntryId,
            'requested_at' => $this->date($now),
        ]);

        return $this->requireRequest((int) $this->connection->lastInsertId());
    }

    public function findRequestByIdempotencyKey(int $organizationId, string $idempotencyKey): ?WithdrawalRequest
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($organizationId <= 0 || $idempotencyKey === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_requests')
            ->where('organization_id = :organization_id')
            ->andWhere('idempotency_key = :idempotency_key')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('idempotency_key', $idempotencyKey)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateRequest($row);
    }

    public function findRequest(int $id): ?WithdrawalRequest
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_requests')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateRequest($row);
    }

    public function findRequestForUpdate(int $id): ?WithdrawalRequest
    {
        if ($id <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM withdrawal_requests WHERE id = ?';
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, [$id]);

        return $row === false ? null : $this->hydrateRequest($row);
    }

    /**
     * @return list<WithdrawalRequest>
     */
    public function listRequests(
        ?WithdrawalReviewStatus $reviewStatus,
        ?WithdrawalPaymentStatus $paymentStatus,
        ?int $organizationId,
        int $limit,
    ): array {
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_requests')
            ->orderBy('requested_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)));

        if ($reviewStatus !== null) {
            $query
                ->andWhere('review_status = :review_status')
                ->setParameter('review_status', $reviewStatus->value);
        }

        if ($paymentStatus !== null) {
            $query
                ->andWhere('payment_status = :payment_status')
                ->setParameter('payment_status', $paymentStatus->value);
        }

        if ($organizationId !== null && $organizationId > 0) {
            $query
                ->andWhere('organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId);
        }

        return array_map(
            fn (array $row): WithdrawalRequest => $this->hydrateRequest($row),
            $query->fetchAllAssociative(),
        );
    }

    public function approveIfPending(
        int $id,
        int $reviewerUserId,
        ?string $reviewerNotes,
        DateTimeImmutable $now,
    ): ?WithdrawalRequest {
        $affected = $this->connection->update('withdrawal_requests', [
            'review_status' => WithdrawalReviewStatus::Approved->value,
            'payment_status' => WithdrawalPaymentStatus::Pending->value,
            'reviewer_user_id' => $reviewerUserId,
            'reviewer_notes' => $reviewerNotes,
            'reviewed_at' => $this->date($now),
            'approved_at' => $this->date($now),
        ], [
            'id' => $id,
            'review_status' => WithdrawalReviewStatus::Pending->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
        ]);

        return $affected < 1 ? null : $this->requireRequest($id);
    }

    public function rejectIfPending(
        int $id,
        int $reviewerUserId,
        ?string $reviewerNotes,
        DateTimeImmutable $now,
    ): ?WithdrawalRequest {
        $affected = $this->connection->update('withdrawal_requests', [
            'review_status' => WithdrawalReviewStatus::Rejected->value,
            'reviewer_user_id' => $reviewerUserId,
            'reviewer_notes' => $reviewerNotes,
            'reviewed_at' => $this->date($now),
            'rejected_at' => $this->date($now),
        ], [
            'id' => $id,
            'review_status' => WithdrawalReviewStatus::Pending->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
        ]);

        return $affected < 1 ? null : $this->requireRequest($id);
    }

    public function revokeIfPending(int $id, DateTimeImmutable $now): ?WithdrawalRequest
    {
        $affected = $this->connection->update('withdrawal_requests', [
            'review_status' => WithdrawalReviewStatus::Revoked->value,
            'revoked_at' => $this->date($now),
        ], [
            'id' => $id,
            'review_status' => WithdrawalReviewStatus::Pending->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
        ]);

        return $affected < 1 ? null : $this->requireRequest($id);
    }

    /**
     * @param array<string, mixed> $payoutAccount
     */
    public function resubmitIfTerminal(
        int $id,
        WithdrawalReviewStatus $expectedReviewStatus,
        array $payoutAccount,
        ?string $applicantNotes,
        int $ledgerEntryId,
        DateTimeImmutable $now,
    ): ?WithdrawalRequest {
        $affected = $this->connection->update('withdrawal_requests', [
            'review_status' => WithdrawalReviewStatus::Pending->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
            'payout_account_json' => json_encode($payoutAccount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'applicant_notes' => $applicantNotes,
            'reviewer_user_id' => null,
            'reviewer_notes' => null,
            'payment_proof_id' => null,
            'payment_completed_by_user_id' => null,
            'payment_notes' => null,
            'ledger_entry_id' => $ledgerEntryId,
            'reviewed_at' => null,
            'approved_at' => null,
            'paid_at' => null,
            'rejected_at' => null,
            'revoked_at' => null,
            'resubmitted_at' => $this->date($now),
        ], [
            'id' => $id,
            'review_status' => $expectedReviewStatus->value,
            'payment_status' => WithdrawalPaymentStatus::NotStarted->value,
        ]);

        return $affected < 1 ? null : $this->requireRequest($id);
    }

    public function markPaidIfReady(
        int $id,
        int $proofId,
        int $completedByUserId,
        ?string $paymentNotes,
        DateTimeImmutable $now,
    ): ?WithdrawalRequest {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
UPDATE withdrawal_requests
SET payment_status = ?,
    payment_proof_id = ?,
    payment_completed_by_user_id = ?,
    payment_notes = ?,
    paid_at = ?
WHERE id = ?
  AND review_status = ?
  AND payment_status = ?
  AND EXISTS (
      SELECT 1
      FROM withdrawal_proofs
      WHERE withdrawal_proofs.id = ?
        AND withdrawal_proofs.withdrawal_request_id = withdrawal_requests.id
        AND withdrawal_proofs.organization_id = withdrawal_requests.organization_id
        AND withdrawal_proofs.status = ?
        AND withdrawal_proofs.checksum IS NOT NULL
        AND withdrawal_proofs.verified_at IS NOT NULL
  )
SQL,
            [
                WithdrawalPaymentStatus::Paid->value,
                $proofId,
                $completedByUserId,
                $paymentNotes,
                $this->date($now),
                $id,
                WithdrawalReviewStatus::Approved->value,
                WithdrawalPaymentStatus::Pending->value,
                $proofId,
                WithdrawalProofStatus::Verified->value,
            ],
        );

        return $affected < 1 ? null : $this->requireRequest($id);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function appendAuditEvent(
        int $withdrawalRequestId,
        int $organizationId,
        ?int $actorUserId,
        string $action,
        ?WithdrawalReviewStatus $fromReviewStatus,
        WithdrawalReviewStatus $toReviewStatus,
        ?WithdrawalPaymentStatus $fromPaymentStatus,
        WithdrawalPaymentStatus $toPaymentStatus,
        ?int $proofId,
        ?string $notes,
        ?array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->connection->insert('withdrawal_audit_events', [
            'withdrawal_request_id' => $withdrawalRequestId,
            'organization_id' => $organizationId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'from_review_status' => $fromReviewStatus?->value,
            'to_review_status' => $toReviewStatus->value,
            'from_payment_status' => $fromPaymentStatus?->value,
            'to_payment_status' => $toPaymentStatus->value,
            'proof_id' => $proofId,
            'notes' => $notes,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $this->date($now),
        ]);
    }

    public function createProof(
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $objectKey,
        string $contentType,
        int $byteSize,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $this->connection->insert('withdrawal_proofs', [
            'withdrawal_request_id' => $withdrawalRequestId,
            'organization_id' => $organizationId,
            'uploaded_by_user_id' => $uploadedByUserId,
            'object_key' => $objectKey,
            'content_type' => $contentType,
            'byte_size' => $byteSize,
            'checksum' => null,
            'status' => WithdrawalProofStatus::PendingUpload->value,
            'verification_error_code' => null,
            'created_at' => $this->date($now),
            'verification_attempted_at' => null,
            'verified_at' => null,
        ]);

        return $this->requireProof((int) $this->connection->lastInsertId());
    }

    public function findProof(int $id): ?WithdrawalProof
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_proofs')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateProof($row);
    }

    public function findProofForRequest(
        int $proofId,
        int $withdrawalRequestId,
    ): ?WithdrawalProof {
        if ($proofId <= 0 || $withdrawalRequestId <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_proofs')
            ->where('id = :id')
            ->andWhere('withdrawal_request_id = :withdrawal_request_id')
            ->setParameter('id', $proofId)
            ->setParameter('withdrawal_request_id', $withdrawalRequestId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateProof($row);
    }

    /** @return list<WithdrawalProof> */
    public function listProofsForRequest(int $withdrawalRequestId, int $limit): array
    {
        if ($withdrawalRequestId <= 0) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_proofs')
            ->where('withdrawal_request_id = :withdrawal_request_id')
            ->setParameter('withdrawal_request_id', $withdrawalRequestId)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->fetchAllAssociative();

        return array_map($this->hydrateProof(...), $rows);
    }

    public function findProofForUpdate(int $proofId, int $withdrawalRequestId): ?WithdrawalProof
    {
        if ($proofId <= 0 || $withdrawalRequestId <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM withdrawal_proofs WHERE id = ? AND withdrawal_request_id = ?';
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, [$proofId, $withdrawalRequestId]);

        return $row === false ? null : $this->hydrateProof($row);
    }

    public function verifyProofIfPending(int $proofId, string $checksum, DateTimeImmutable $now): ?WithdrawalProof
    {
        $affected = $this->connection->update('withdrawal_proofs', [
            'checksum' => $checksum,
            'status' => WithdrawalProofStatus::Verified->value,
            'verification_error_code' => null,
            'verification_attempted_at' => $this->date($now),
            'verified_at' => $this->date($now),
        ], [
            'id' => $proofId,
            'status' => WithdrawalProofStatus::PendingUpload->value,
        ]);

        return $affected < 1 ? null : $this->requireProof($proofId);
    }

    public function rejectProofIfPending(int $proofId, string $errorCode, DateTimeImmutable $now): ?WithdrawalProof
    {
        $affected = $this->connection->update('withdrawal_proofs', [
            'checksum' => null,
            'status' => WithdrawalProofStatus::Rejected->value,
            'verification_error_code' => $errorCode,
            'verification_attempted_at' => $this->date($now),
            'verified_at' => null,
        ], [
            'id' => $proofId,
            'status' => WithdrawalProofStatus::PendingUpload->value,
        ]);

        return $affected < 1 ? null : $this->requireProof($proofId);
    }

    /** @param array<string, mixed> $row */
    private function hydrateRequest(array $row): WithdrawalRequest
    {
        $payoutAccount = json_decode((string) $row['payout_account_json'], true, flags: JSON_THROW_ON_ERROR);

        return new WithdrawalRequest(
            id: (int) $row['id'],
            organizationId: (int) $row['organization_id'],
            requestedByUserId: (int) $row['requested_by_user_id'],
            pointsAmount: (int) $row['points_amount'],
            amountCny: (string) $row['amount_cny'],
            pointsPerCny: (int) $row['points_per_cny'],
            idempotencyKey: (string) $row['idempotency_key'],
            reviewStatus: WithdrawalReviewStatus::from((string) $row['review_status']),
            paymentStatus: WithdrawalPaymentStatus::from((string) $row['payment_status']),
            payoutMethod: (string) $row['payout_method'],
            payoutAccount: is_array($payoutAccount) ? $payoutAccount : [],
            applicantNotes: $this->nullableString($row['applicant_notes']),
            reviewerUserId: $row['reviewer_user_id'] === null ? null : (int) $row['reviewer_user_id'],
            reviewerNotes: $this->nullableString($row['reviewer_notes']),
            paymentProofId: $row['payment_proof_id'] === null ? null : (int) $row['payment_proof_id'],
            paymentCompletedByUserId: $row['payment_completed_by_user_id'] === null ? null : (int) $row['payment_completed_by_user_id'],
            paymentNotes: $this->nullableString($row['payment_notes']),
            ledgerEntryId: (int) $row['ledger_entry_id'],
            requestedAt: new DateTimeImmutable((string) $row['requested_at']),
            reviewedAt: $this->nullableDate($row['reviewed_at']),
            approvedAt: $this->nullableDate($row['approved_at']),
            paidAt: $this->nullableDate($row['paid_at']),
            rejectedAt: $this->nullableDate($row['rejected_at']),
            revokedAt: $this->nullableDate($row['revoked_at']),
            resubmittedAt: $this->nullableDate($row['resubmitted_at']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateProof(array $row): WithdrawalProof
    {
        return new WithdrawalProof(
            id: (int) $row['id'],
            withdrawalRequestId: (int) $row['withdrawal_request_id'],
            organizationId: (int) $row['organization_id'],
            uploadedByUserId: (int) $row['uploaded_by_user_id'],
            objectKey: (string) $row['object_key'],
            contentType: (string) $row['content_type'],
            byteSize: (int) $row['byte_size'],
            checksum: $this->nullableString($row['checksum']),
            status: WithdrawalProofStatus::from((string) $row['status']),
            verificationErrorCode: $this->nullableString($row['verification_error_code']),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            verificationAttemptedAt: $this->nullableDate($row['verification_attempted_at']),
            verifiedAt: $this->nullableDate($row['verified_at']),
        );
    }

    private function requireRequest(int $id): WithdrawalRequest
    {
        $request = $this->findRequest($id);
        if (!$request instanceof WithdrawalRequest) {
            throw new \RuntimeException('withdrawal_request_persistence_failed');
        }

        return $request;
    }

    private function requireProof(int $id): WithdrawalProof
    {
        $proof = $this->findProof($id);
        if (!$proof instanceof WithdrawalProof) {
            throw new \RuntimeException('withdrawal_proof_persistence_failed');
        }

        return $proof;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
