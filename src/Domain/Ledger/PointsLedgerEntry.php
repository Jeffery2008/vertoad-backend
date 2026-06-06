<?php

declare(strict_types=1);

namespace VertoAD\Domain\Ledger;

use InvalidArgumentException;

final readonly class PointsLedgerEntry
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public ?int $id,
        public int $organizationId,
        public string $accountType,
        public ?int $accountId,
        public int $pointsAmount,
        public LedgerDirection $direction,
        public ?int $balanceAfterPoints,
        public ?string $referenceType,
        public ?int $referenceId,
        public string $idempotencyKey,
        public ?string $memo,
        public ?array $metadata,
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('Ledger organization ID must be positive.');
        }

        if (trim($this->accountType) === '') {
            throw new InvalidArgumentException('Ledger account type is required.');
        }

        if ($this->accountId !== null && $this->accountId <= 0) {
            throw new InvalidArgumentException('Ledger account ID must be positive when provided.');
        }

        if ($this->pointsAmount <= 0) {
            throw new InvalidArgumentException('Ledger points amount must be positive.');
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Ledger idempotency key is required.');
        }
    }
}
