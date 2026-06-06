<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Ledger\PointsLedgerEntry;

interface PointsLedgerRepositoryInterface
{
    public function append(PointsLedgerEntry $entry): PointsLedgerEntry;

    public function findById(int $id): ?PointsLedgerEntry;

    public function findByIdempotencyKey(string $idempotencyKey): ?PointsLedgerEntry;
}
