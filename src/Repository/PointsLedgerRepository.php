<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;

final class PointsLedgerRepository implements PointsLedgerRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(PointsLedgerEntry $entry): PointsLedgerEntry
    {
        $this->connection->insert(
            'ledger_entries',
            [
                'organization_id' => $entry->organizationId,
                'account_type' => $entry->accountType,
                'account_id' => $entry->accountId,
                'points_amount' => $entry->pointsAmount,
                'direction' => $entry->direction->value,
                'balance_after_points' => $entry->balanceAfterPoints,
                'reference_type' => $entry->referenceType,
                'reference_id' => $entry->referenceId,
                'idempotency_key' => $entry->idempotencyKey,
                'memo' => $entry->memo,
                'metadata_json' => $entry->metadata === null
                    ? null
                    : json_encode($entry->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
            [
                'organization_id' => ParameterType::INTEGER,
                'account_type' => ParameterType::STRING,
                'account_id' => $entry->accountId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'points_amount' => ParameterType::INTEGER,
                'direction' => ParameterType::STRING,
                'balance_after_points' => $entry->balanceAfterPoints === null ? ParameterType::NULL : ParameterType::INTEGER,
                'reference_type' => $entry->referenceType === null ? ParameterType::NULL : ParameterType::STRING,
                'reference_id' => $entry->referenceId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'idempotency_key' => ParameterType::STRING,
                'memo' => $entry->memo === null ? ParameterType::NULL : ParameterType::STRING,
                'metadata_json' => $entry->metadata === null ? ParameterType::NULL : ParameterType::STRING,
            ],
        );

        $id = (int) $this->connection->lastInsertId();

        return new PointsLedgerEntry(
            id: $id > 0 ? $id : null,
            organizationId: $entry->organizationId,
            accountType: $entry->accountType,
            accountId: $entry->accountId,
            pointsAmount: $entry->pointsAmount,
            direction: $entry->direction,
            balanceAfterPoints: $entry->balanceAfterPoints,
            referenceType: $entry->referenceType,
            referenceId: $entry->referenceId,
            idempotencyKey: $entry->idempotencyKey,
            memo: $entry->memo,
            metadata: $entry->metadata,
        );
    }

    public function findById(int $id): ?PointsLedgerEntry
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'organization_id',
                'account_type',
                'account_id',
                'points_amount',
                'direction',
                'balance_after_points',
                'reference_type',
                'reference_id',
                'idempotency_key',
                'memo',
                'metadata_json',
            )
            ->from('ledger_entries')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?PointsLedgerEntry
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'organization_id',
                'account_type',
                'account_id',
                'points_amount',
                'direction',
                'balance_after_points',
                'reference_type',
                'reference_id',
                'idempotency_key',
                'memo',
                'metadata_json',
            )
            ->from('ledger_entries')
            ->where('idempotency_key = :idempotency_key')
            ->setParameter('idempotency_key', $idempotencyKey)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<PointsLedgerEntry>
     */
    public function listForOrganization(int $organizationId, int $limit = 50): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'organization_id',
                'account_type',
                'account_id',
                'points_amount',
                'direction',
                'balance_after_points',
                'reference_type',
                'reference_id',
                'idempotency_key',
                'memo',
                'metadata_json',
            )
            ->from('ledger_entries')
            ->where('organization_id = :organization_id')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): PointsLedgerEntry => $this->hydrate($row), $rows);
    }

    public function balanceForOrganization(int $organizationId, string $accountType = 'advertiser_balance'): int
    {
        if ($organizationId <= 0 || trim($accountType) === '') {
            return 0;
        }

        $balance = $this->connection->createQueryBuilder()
            ->select(
                "COALESCE(SUM(CASE WHEN direction = 'credit' THEN points_amount ELSE -points_amount END), 0)",
            )
            ->from('ledger_entries')
            ->where('organization_id = :organization_id')
            ->andWhere('account_type = :account_type')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('account_type', trim($accountType))
            ->fetchOne();

        return (int) $balance;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PointsLedgerEntry
    {
        $metadata = null;
        if ($row['metadata_json'] !== null) {
            $decoded = json_decode((string) $row['metadata_json'], true, flags: JSON_THROW_ON_ERROR);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return new PointsLedgerEntry(
            id: (int) $row['id'],
            organizationId: (int) $row['organization_id'],
            accountType: (string) $row['account_type'],
            accountId: $row['account_id'] === null ? null : (int) $row['account_id'],
            pointsAmount: (int) $row['points_amount'],
            direction: LedgerDirection::from((string) $row['direction']),
            balanceAfterPoints: $row['balance_after_points'] === null ? null : (int) $row['balance_after_points'],
            referenceType: $row['reference_type'] === null ? null : (string) $row['reference_type'],
            referenceId: $row['reference_id'] === null ? null : (int) $row['reference_id'],
            idempotencyKey: (string) $row['idempotency_key'],
            memo: $row['memo'] === null ? null : (string) $row['memo'],
            metadata: $metadata,
        );
    }
}
