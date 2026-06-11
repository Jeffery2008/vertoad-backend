<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Ledger\PointsLedgerEntry;

interface PointsLedgerRepositoryInterface
{
    public function append(PointsLedgerEntry $entry): PointsLedgerEntry;

    public function tryDebit(PointsLedgerEntry $entry): ?PointsLedgerEntry;

    public function findById(int $id): ?PointsLedgerEntry;

    public function findByIdempotencyKey(string $idempotencyKey): ?PointsLedgerEntry;

    public function findReversalForEntry(int $entryId): ?PointsLedgerEntry;

    /**
     * @return list<PointsLedgerEntry>
     */
    public function listForOrganization(int $organizationId, int $limit = 50, ?string $accountType = null): array;

    public function balanceForOrganization(int $organizationId, string $accountType = 'advertiser_balance'): int;
}
