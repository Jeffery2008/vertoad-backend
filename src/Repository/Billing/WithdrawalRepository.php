<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Domain\Billing\WithdrawalStatus;

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
            'status' => WithdrawalStatus::Requested->value,
            'payout_method' => $payoutMethod,
            'payout_account_json' => json_encode($payoutAccount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'applicant_notes' => $notes,
            'ledger_entry_id' => $ledgerEntryId,
            'requested_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $request = $this->findRequest((int) $this->connection->lastInsertId());
        assert($request instanceof WithdrawalRequest);
        return $request;
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

    /**
     * @return list<WithdrawalRequest>
     */
    public function listRequests(
        ?WithdrawalStatus $status,
        ?int $organizationId,
        int $limit,
    ): array {
        $limit = max(1, min(200, $limit));
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('withdrawal_requests')
            ->orderBy('requested_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $query
                ->where('status = :status')
                ->setParameter('status', $status->value);
        }

        if ($organizationId !== null && $organizationId > 0) {
            $query
                ->andWhere('organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId);
        }

        $rows = $query->fetchAllAssociative();

        return array_map(fn (array $row): WithdrawalRequest => $this->hydrateRequest($row), $rows);
    }

    /**
     * @param array<string, mixed>|null $payoutAccount
     */
    public function updateRequestStateIfCurrent(
        int $id,
        WithdrawalStatus $expectedStatus,
        WithdrawalStatus $status,
        ?int $reviewerUserId,
        ?string $reviewerNotes,
        ?array $payoutAccount,
        ?int $ledgerEntryId,
        DateTimeImmutable $now,
    ): ?WithdrawalRequest {
        $affected = $this->connection->update(
            'withdrawal_requests',
            $this->stateFields($status, $reviewerUserId, $reviewerNotes, $payoutAccount, $ledgerEntryId, $now),
            ['id' => $id, 'status' => $expectedStatus->value],
        );

        if ($affected < 1) {
            return null;
        }

        $request = $this->findRequest($id);
        assert($request instanceof WithdrawalRequest);
        return $request;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function appendAuditEvent(
        int $withdrawalRequestId,
        int $organizationId,
        ?int $actorUserId,
        string $action,
        ?WithdrawalStatus $fromStatus,
        WithdrawalStatus $toStatus,
        ?string $notes,
        ?array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->connection->insert('withdrawal_audit_events', [
            'withdrawal_request_id' => $withdrawalRequestId,
            'organization_id' => $organizationId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'notes' => $notes,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $now->format('Y-m-d H:i:s'),
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
            'status' => 'pending_upload',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'confirmed_at' => null,
        ]);

        $proof = $this->findProof((int) $this->connection->lastInsertId());
        assert($proof instanceof WithdrawalProof);
        return $proof;
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

    public function confirmProof(
        int $proofId,
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $objectKey,
        string $contentType,
        int $byteSize,
        string $checksum,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $affected = $this->connection->update('withdrawal_proofs', [
            'object_key' => $objectKey,
            'content_type' => $contentType,
            'byte_size' => $byteSize,
            'checksum' => $checksum,
            'status' => 'confirmed',
            'confirmed_at' => $now->format('Y-m-d H:i:s'),
        ], [
            'id' => $proofId,
            'withdrawal_request_id' => $withdrawalRequestId,
            'organization_id' => $organizationId,
            'uploaded_by_user_id' => $uploadedByUserId,
        ]);
        if ($affected < 1) {
            throw new \RuntimeException('withdrawal_proof_not_found');
        }

        $proof = $this->findProof($proofId);
        assert($proof instanceof WithdrawalProof);
        return $proof;
    }

    /**
     * @param array<string, mixed> $row
     */
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
            status: WithdrawalStatus::from((string) $row['status']),
            payoutMethod: (string) $row['payout_method'],
            payoutAccount: is_array($payoutAccount) ? $payoutAccount : [],
            applicantNotes: $row['applicant_notes'] === null ? null : (string) $row['applicant_notes'],
            reviewerUserId: $row['reviewer_user_id'] === null ? null : (int) $row['reviewer_user_id'],
            reviewerNotes: $row['reviewer_notes'] === null ? null : (string) $row['reviewer_notes'],
            ledgerEntryId: (int) $row['ledger_entry_id'],
            requestedAt: new DateTimeImmutable((string) $row['requested_at']),
            reviewedAt: $this->nullableDate($row['reviewed_at']),
            paidAt: $this->nullableDate($row['paid_at']),
            rejectedAt: $this->nullableDate($row['rejected_at']),
            revokedAt: $this->nullableDate($row['revoked_at']),
            resubmittedAt: $this->nullableDate($row['resubmitted_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
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
            checksum: $row['checksum'] === null ? null : (string) $row['checksum'],
            status: (string) $row['status'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            confirmedAt: $this->nullableDate($row['confirmed_at']),
        );
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    /**
     * @param array<string, mixed>|null $payoutAccount
     * @return array<string, mixed>
     */
    private function stateFields(
        WithdrawalStatus $status,
        ?int $reviewerUserId,
        ?string $reviewerNotes,
        ?array $payoutAccount,
        ?int $ledgerEntryId,
        DateTimeImmutable $now,
    ): array {
        $fields = [
            'status' => $status->value,
            'reviewer_user_id' => $reviewerUserId,
            'reviewer_notes' => $reviewerNotes,
        ];

        if ($payoutAccount !== null) {
            $fields['payout_account_json'] = json_encode($payoutAccount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        if ($ledgerEntryId !== null) {
            $fields['ledger_entry_id'] = $ledgerEntryId;
        }

        match ($status) {
            WithdrawalStatus::Paid => $fields['paid_at'] = $now->format('Y-m-d H:i:s'),
            WithdrawalStatus::Rejected => $fields['rejected_at'] = $now->format('Y-m-d H:i:s'),
            WithdrawalStatus::Revoked => $fields['revoked_at'] = $now->format('Y-m-d H:i:s'),
            WithdrawalStatus::Requested => $fields['resubmitted_at'] = $now->format('Y-m-d H:i:s'),
        };

        if ($status === WithdrawalStatus::Paid || $status === WithdrawalStatus::Rejected) {
            $fields['reviewed_at'] = $now->format('Y-m-d H:i:s');
        }

        return $fields;
    }
}
