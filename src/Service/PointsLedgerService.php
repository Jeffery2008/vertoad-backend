<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\PointsLedgerRepositoryInterface;

final class PointsLedgerService
{
    public function __construct(private readonly PointsLedgerRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function credit(
        int $organizationId,
        string $accountType,
        ?int $accountId,
        int $pointsAmount,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $memo = null,
        ?array $metadata = null,
    ): PointsLedgerEntry {
        return $this->append(
            organizationId: $organizationId,
            accountType: $accountType,
            accountId: $accountId,
            pointsAmount: $pointsAmount,
            direction: LedgerDirection::Credit,
            idempotencyKey: $idempotencyKey,
            referenceType: $referenceType,
            referenceId: $referenceId,
            memo: $memo,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function debit(
        int $organizationId,
        string $accountType,
        ?int $accountId,
        int $pointsAmount,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $memo = null,
        ?array $metadata = null,
    ): PointsLedgerEntry {
        return $this->append(
            organizationId: $organizationId,
            accountType: $accountType,
            accountId: $accountId,
            pointsAmount: $pointsAmount,
            direction: LedgerDirection::Debit,
            idempotencyKey: $idempotencyKey,
            referenceType: $referenceType,
            referenceId: $referenceId,
            memo: $memo,
            metadata: $metadata,
        );
    }

    public function reverse(
        int $originalEntryId,
        string $idempotencyKey,
        string $reason,
        ?int $actorUserId = null,
    ): PointsLedgerEntry {
        $original = $this->repository->findById($originalEntryId);
        if ($original === null) {
            throw new InvalidArgumentException('Original ledger entry was not found.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Ledger reversal reason is required.');
        }

        $existing = $this->repository->findByIdempotencyKey(trim($idempotencyKey));
        if ($existing !== null) {
            return $existing;
        }

        if ($this->repository->findReversalForEntry($originalEntryId) !== null) {
            throw new InvalidArgumentException('Original ledger entry has already been reversed.');
        }

        return $this->append(
            organizationId: $original->organizationId,
            accountType: $original->accountType,
            accountId: $original->accountId,
            pointsAmount: $original->pointsAmount,
            direction: $original->direction->reverse(),
            idempotencyKey: $idempotencyKey,
            referenceType: 'ledger_entry',
            referenceId: $original->id,
            memo: 'Reversal: ' . $reason,
            metadata: [
                'actor_user_id' => $actorUserId,
                'entry_kind' => 'reversal',
                'reason' => $reason,
                'reverses_ledger_entry_id' => $original->id,
            ],
        );
    }

    public function adjust(
        int $organizationId,
        string $accountType,
        ?int $accountId,
        int $pointsAmount,
        LedgerDirection $direction,
        string $idempotencyKey,
        string $reason,
        ?int $actorUserId = null,
    ): PointsLedgerEntry {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Ledger adjustment reason is required.');
        }

        return $this->append(
            organizationId: $organizationId,
            accountType: $accountType,
            accountId: $accountId,
            pointsAmount: $pointsAmount,
            direction: $direction,
            idempotencyKey: $idempotencyKey,
            referenceType: 'manual_adjustment',
            referenceId: null,
            memo: 'Adjustment: ' . $reason,
            metadata: [
                'actor_user_id' => $actorUserId,
                'entry_kind' => 'adjustment',
                'reason' => $reason,
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function append(
        int $organizationId,
        string $accountType,
        ?int $accountId,
        int $pointsAmount,
        LedgerDirection $direction,
        string $idempotencyKey,
        ?string $referenceType,
        ?int $referenceId,
        ?string $memo,
        ?array $metadata,
    ): PointsLedgerEntry {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Ledger idempotency key is required.');
        }

        $existing = $this->repository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $previousBalance = $this->repository->balanceForOrganization($organizationId, trim($accountType));
        $balanceAfterPoints = match ($direction) {
            LedgerDirection::Credit => $previousBalance + $pointsAmount,
            LedgerDirection::Debit => $previousBalance - $pointsAmount,
        };

        return $this->repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: $organizationId,
            accountType: trim($accountType),
            accountId: $accountId,
            pointsAmount: $pointsAmount,
            direction: $direction,
            balanceAfterPoints: $balanceAfterPoints,
            referenceType: $this->normalizeNullableText($referenceType),
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
            memo: $this->normalizeNullableText($memo),
            metadata: $this->normalizeMetadata($metadata),
        ));
    }

    private function normalizeNullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        ksort($metadata);

        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadata($value);
            }
        }

        return $metadata;
    }
}
