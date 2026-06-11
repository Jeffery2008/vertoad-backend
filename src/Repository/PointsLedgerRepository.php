<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Ledger\InsufficientLedgerBalanceException;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;

final class PointsLedgerRepository implements PointsLedgerRepositoryInterface
{
    private ?bool $hasBalanceTable = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(PointsLedgerEntry $entry): PointsLedgerEntry
    {
        $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
        if ($existing !== null) {
            $this->assertSameIdempotentEntry($existing, $entry);

            return $existing;
        }

        try {
            return $this->connection->transactional(function () use ($entry): PointsLedgerEntry {
                $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
                if ($existing !== null) {
                    $this->assertSameIdempotentEntry($existing, $entry);

                    return $existing;
                }

                $balanceAfterPoints = $this->nextBalanceAfter(
                    $entry,
                    failOnInsufficientDebit: $entry->direction === LedgerDirection::Debit,
                );
                $storedEntry = new PointsLedgerEntry(
                    id: null,
                    organizationId: $entry->organizationId,
                    accountType: $entry->accountType,
                    accountId: $entry->accountId,
                    pointsAmount: $entry->pointsAmount,
                    direction: $entry->direction,
                    balanceAfterPoints: $balanceAfterPoints,
                    referenceType: $entry->referenceType,
                    referenceId: $entry->referenceId,
                    idempotencyKey: $entry->idempotencyKey,
                    memo: $entry->memo,
                    metadata: $entry->metadata,
                );

                return $this->insertLedgerEntry($storedEntry);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
            if ($existing !== null) {
                $this->assertSameIdempotentEntry($existing, $entry);

                return $existing;
            }

            throw $exception;
        }
    }

    public function tryDebit(PointsLedgerEntry $entry): ?PointsLedgerEntry
    {
        if ($entry->direction !== LedgerDirection::Debit) {
            throw new InvalidArgumentException('tryDebit requires a debit ledger entry.');
        }

        $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
        if ($existing !== null) {
            $this->assertSameIdempotentEntry($existing, $entry);

            return $existing;
        }

        try {
            return $this->connection->transactional(function () use ($entry): ?PointsLedgerEntry {
                $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
                if ($existing !== null) {
                    $this->assertSameIdempotentEntry($existing, $entry);

                    return $existing;
                }

                $balanceAfterPoints = $this->nextBalanceAfter($entry, failOnInsufficientDebit: true);
                $storedEntry = new PointsLedgerEntry(
                    id: null,
                    organizationId: $entry->organizationId,
                    accountType: $entry->accountType,
                    accountId: $entry->accountId,
                    pointsAmount: $entry->pointsAmount,
                    direction: $entry->direction,
                    balanceAfterPoints: $balanceAfterPoints,
                    referenceType: $entry->referenceType,
                    referenceId: $entry->referenceId,
                    idempotencyKey: $entry->idempotencyKey,
                    memo: $entry->memo,
                    metadata: $entry->metadata,
                );

                return $this->insertLedgerEntry($storedEntry);
            });
        } catch (InsufficientLedgerBalanceException) {
            return null;
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotencyKey($entry->idempotencyKey);
            if ($existing !== null) {
                $this->assertSameIdempotentEntry($existing, $entry);

                return $existing;
            }

            throw $exception;
        }
    }

    private function insertLedgerEntry(PointsLedgerEntry $entry): PointsLedgerEntry
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

    public function findReversalForEntry(int $entryId): ?PointsLedgerEntry
    {
        if ($entryId <= 0) {
            return null;
        }

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
            ->where('reference_type = :reference_type')
            ->andWhere('reference_id = :reference_id')
            ->orderBy('id', 'DESC')
            ->setParameter('reference_type', 'ledger_entry')
            ->setParameter('reference_id', $entryId)
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $entry = $this->hydrate($row);
            if (($entry->metadata['entry_kind'] ?? null) === 'reversal') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<PointsLedgerEntry>
     */
    public function listForOrganization(int $organizationId, int $limit = 50, ?string $accountType = null): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $query = $this->connection->createQueryBuilder()
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
            ->setParameter('organization_id', $organizationId);

        if ($accountType !== null && trim($accountType) !== '') {
            $query
                ->andWhere('account_type = :account_type')
                ->setParameter('account_type', trim($accountType));
        }

        $rows = $query->fetchAllAssociative();

        return array_map(fn (array $row): PointsLedgerEntry => $this->hydrate($row), $rows);
    }

    public function balanceForOrganization(int $organizationId, string $accountType = 'advertiser_balance'): int
    {
        if ($organizationId <= 0 || trim($accountType) === '') {
            return 0;
        }

        $accountType = trim($accountType);
        if ($this->hasBalanceTableForRead()) {
            $balance = $this->connection->createQueryBuilder()
                ->select('balance_points')
                ->from('ledger_account_balances')
                ->where('organization_id = :organization_id')
                ->andWhere('account_type = :account_type')
                ->setParameter('organization_id', $organizationId)
                ->setParameter('account_type', $accountType)
                ->fetchOne();

            if ($balance !== false && $balance !== null) {
                return (int) $balance;
            }
        }

        return $this->sumBalanceForOrganization($organizationId, $accountType);
    }

    private function nextBalanceAfter(PointsLedgerEntry $entry, bool $failOnInsufficientDebit = false): int
    {
        $this->requireBalanceTableForWrite();

        $previousBalance = $this->lockedBalanceForAccount($entry->organizationId, $entry->accountType);
        if ($failOnInsufficientDebit && $entry->direction === LedgerDirection::Debit && $previousBalance < $entry->pointsAmount) {
            throw new InsufficientLedgerBalanceException();
        }

        $balanceAfterPoints = $this->applyDirection($previousBalance, $entry);

        $this->connection->update(
            'ledger_account_balances',
            [
                'balance_points' => $balanceAfterPoints,
                'updated_at' => $this->nowSql(),
            ],
            [
                'organization_id' => $entry->organizationId,
                'account_type' => $entry->accountType,
            ],
            [
                'balance_points' => ParameterType::INTEGER,
                'updated_at' => ParameterType::STRING,
                'organization_id' => ParameterType::INTEGER,
                'account_type' => ParameterType::STRING,
            ],
        );

        return $balanceAfterPoints;
    }

    private function lockedBalanceForAccount(int $organizationId, string $accountType): int
    {
        $this->ensureBalanceRow($organizationId, $accountType);
        $sql = 'SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = ?';
        if ($this->supportsForUpdate()) {
            $sql .= ' FOR UPDATE';
        }

        return (int) $this->connection->fetchOne($sql, [$organizationId, $accountType]);
    }

    private function ensureBalanceRow(int $organizationId, string $accountType): void
    {
        $existing = $this->connection->fetchOne(
            'SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = ?',
            [$organizationId, $accountType],
        );
        if ($existing !== false && $existing !== null) {
            return;
        }

        $currentBalance = $this->sumBalanceForOrganization($organizationId, $accountType);
        if ($this->isSqlite()) {
            $this->connection->executeStatement(
                'INSERT OR IGNORE INTO ledger_account_balances (organization_id, account_type, balance_points, updated_at) VALUES (?, ?, ?, ?)',
                [$organizationId, $accountType, $currentBalance, $this->nowSql()],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO ledger_account_balances (organization_id, account_type, balance_points, updated_at) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE updated_at = updated_at',
            [$organizationId, $accountType, $currentBalance, $this->nowSql()],
        );
    }

    private function sumBalanceForOrganization(int $organizationId, string $accountType): int
    {
        $balance = $this->connection->createQueryBuilder()
            ->select(
                "COALESCE(SUM(CASE WHEN direction = 'credit' THEN points_amount ELSE -points_amount END), 0)",
            )
            ->from('ledger_entries')
            ->where('organization_id = :organization_id')
            ->andWhere('account_type = :account_type')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('account_type', $accountType)
            ->fetchOne();

        return (int) $balance;
    }

    private function applyDirection(int $previousBalance, PointsLedgerEntry $entry): int
    {
        return match ($entry->direction) {
            LedgerDirection::Credit => $previousBalance + $entry->pointsAmount,
            LedgerDirection::Debit => $previousBalance - $entry->pointsAmount,
        };
    }

    private function hasBalanceTableForRead(): bool
    {
        if ($this->hasBalanceTable !== null) {
            return $this->hasBalanceTable;
        }

        try {
            $this->hasBalanceTable = $this->connection->createSchemaManager()->tablesExist(['ledger_account_balances']);
        } catch (\Throwable) {
            $this->hasBalanceTable = false;
        }

        return $this->hasBalanceTable;
    }

    private function requireBalanceTableForWrite(): void
    {
        if ($this->hasBalanceTable !== null) {
            if (!$this->hasBalanceTable) {
                throw new RuntimeException('Ledger balance table is required for ledger writes.');
            }

            return;
        }

        try {
            $this->hasBalanceTable = $this->connection->createSchemaManager()->tablesExist(['ledger_account_balances']);
        } catch (\Throwable $exception) {
            $this->hasBalanceTable = false;

            throw new RuntimeException('Ledger balance table is required for ledger writes.', previous: $exception);
        }

        if (!$this->hasBalanceTable) {
            throw new RuntimeException('Ledger balance table is required for ledger writes.');
        }
    }

    private function supportsForUpdate(): bool
    {
        return !$this->isSqlite();
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function nowSql(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function assertSameIdempotentEntry(PointsLedgerEntry $existing, PointsLedgerEntry $requested): void
    {
        if (
            $existing->organizationId !== $requested->organizationId
            || $existing->accountType !== $requested->accountType
            || $existing->accountId !== $requested->accountId
            || $existing->pointsAmount !== $requested->pointsAmount
            || $existing->direction !== $requested->direction
            || $existing->referenceType !== $requested->referenceType
            || $existing->referenceId !== $requested->referenceId
        ) {
            throw new InvalidArgumentException('Ledger idempotency key conflicts with an existing entry.');
        }
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
