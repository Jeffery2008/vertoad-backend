<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SpendReservation
{
    public function __construct(
        public ?int $id,
        public string $reservationId,
        public int $organizationId,
        public int $campaignId,
        public int $pointsAmount,
        public SpendReservationStatus $status,
        public DateTimeImmutable $reservedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $committedAt,
        public ?DateTimeImmutable $releasedAt,
        public ?DateTimeImmutable $expiredAt,
        public ?int $ledgerEntryId,
    ) {
        if (trim($this->reservationId) === '') {
            throw new InvalidArgumentException('Spend reservation ID is required.');
        }

        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('Spend reservation organization ID must be positive.');
        }

        if ($this->campaignId <= 0) {
            throw new InvalidArgumentException('Spend reservation campaign ID must be positive.');
        }

        if ($this->pointsAmount <= 0) {
            throw new InvalidArgumentException('Spend reservation points amount must be positive.');
        }

        if ($this->ledgerEntryId !== null && $this->ledgerEntryId <= 0) {
            throw new InvalidArgumentException('Spend reservation ledger entry ID must be positive when provided.');
        }
    }
}
