<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CpmBillingAllocation
{
    public function __construct(
        public ?int $id,
        public string $eventKey,
        public string $eventType,
        public string $eventId,
        public string $decisionId,
        public CpmBillingStream $stream,
        public CpmRevenueShareSnapshot $revenueShare,
        public ?int $accumulatorId,
        public int $bidPointsPerThousand,
        public int $assessedGrossPoints,
        public CpmBillingAllocationStatus $status,
        public ?string $reason,
        public int $grossRemainderBefore,
        public int $grossPoints,
        public int $grossRemainderAfter,
        public int $publisherShareRemainderBefore,
        public int $publisherPoints,
        public int $publisherShareRemainderAfter,
        public int $platformPoints,
        public ?int $advertiserLedgerEntryId,
        public ?int $publisherLedgerEntryId,
        public ?string $reservationId,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $processedAt = null,
    ) {
        if ($this->id !== null && $this->id <= 0) {
            throw new InvalidArgumentException('CPM allocation ID must be positive when provided.');
        }
        if ($this->accumulatorId !== null && $this->accumulatorId <= 0) {
            throw new InvalidArgumentException('CPM accumulator ID must be positive when provided.');
        }
        if (trim($this->eventKey) === '' || trim($this->eventId) === '' || trim($this->decisionId) === '') {
            throw new InvalidArgumentException('CPM allocation event identity is required.');
        }
        if ($this->eventType !== 'impression') {
            throw new InvalidArgumentException('CPM allocation event type must be impression.');
        }
        if ($this->bidPointsPerThousand <= 0) {
            throw new InvalidArgumentException('CPM allocation bid points per thousand must be positive.');
        }
        if ($this->assessedGrossPoints < 0 || $this->grossPoints < 0 || $this->publisherPoints < 0) {
            throw new InvalidArgumentException('CPM gross and publisher allocation points cannot be negative.');
        }
        if ($this->grossPoints !== $this->publisherPoints + $this->platformPoints) {
            throw new InvalidArgumentException('CPM allocation points must reconcile.');
        }
        if ($this->assessedGrossPoints < $this->grossPoints) {
            throw new InvalidArgumentException('CPM assessed points cannot be below actual points.');
        }
        if ($this->grossRemainderBefore < 0 || $this->grossRemainderBefore >= 1_000 || $this->grossRemainderAfter < 0 || $this->grossRemainderAfter >= 1_000) {
            throw new InvalidArgumentException('CPM allocation gross remainders are invalid.');
        }
        if (
            $this->publisherShareRemainderBefore < 0
            || $this->publisherShareRemainderBefore >= CpmBillingAccumulator::PUBLISHER_SHARE_DENOMINATOR
            || $this->publisherShareRemainderAfter < 0
            || $this->publisherShareRemainderAfter >= CpmBillingAccumulator::PUBLISHER_SHARE_DENOMINATOR
        ) {
            throw new InvalidArgumentException('CPM allocation publisher remainders are invalid.');
        }
        if ($this->advertiserLedgerEntryId !== null && $this->advertiserLedgerEntryId <= 0) {
            throw new InvalidArgumentException('CPM advertiser ledger entry ID must be positive when provided.');
        }
        if ($this->publisherLedgerEntryId !== null && $this->publisherLedgerEntryId <= 0) {
            throw new InvalidArgumentException('CPM publisher ledger entry ID must be positive when provided.');
        }

        $this->assertLifecycleState();
    }

    public function withId(?int $id): self
    {
        return new self(
            id: $id,
            eventKey: $this->eventKey,
            eventType: $this->eventType,
            eventId: $this->eventId,
            decisionId: $this->decisionId,
            stream: $this->stream,
            revenueShare: $this->revenueShare,
            accumulatorId: $this->accumulatorId,
            bidPointsPerThousand: $this->bidPointsPerThousand,
            assessedGrossPoints: $this->assessedGrossPoints,
            status: $this->status,
            reason: $this->reason,
            grossRemainderBefore: $this->grossRemainderBefore,
            grossPoints: $this->grossPoints,
            grossRemainderAfter: $this->grossRemainderAfter,
            publisherShareRemainderBefore: $this->publisherShareRemainderBefore,
            publisherPoints: $this->publisherPoints,
            publisherShareRemainderAfter: $this->publisherShareRemainderAfter,
            platformPoints: $this->platformPoints,
            advertiserLedgerEntryId: $this->advertiserLedgerEntryId,
            publisherLedgerEntryId: $this->publisherLedgerEntryId,
            reservationId: $this->reservationId,
            occurredAt: $this->occurredAt,
            processedAt: $this->processedAt,
        );
    }

    private function assertLifecycleState(): void
    {
        if ($this->status === CpmBillingAllocationStatus::Processing) {
            if (
                $this->processedAt !== null
                || $this->reason !== null
                || $this->assessedGrossPoints !== 0
                || $this->grossPoints !== 0
                || $this->publisherPoints !== 0
                || $this->platformPoints !== 0
                || $this->advertiserLedgerEntryId !== null
                || $this->publisherLedgerEntryId !== null
                || $this->reservationId !== null
            ) {
                throw new InvalidArgumentException('Processing CPM allocation must not contain settlement state.');
            }

            return;
        }

        if ($this->processedAt === null) {
            throw new InvalidArgumentException('Final CPM allocation must have a processing timestamp.');
        }

        if ($this->status === CpmBillingAllocationStatus::Accrued) {
            if (
                $this->assessedGrossPoints !== 0
                || $this->grossPoints !== 0
                || $this->publisherPoints !== 0
                || $this->platformPoints !== 0
                || $this->advertiserLedgerEntryId !== null
                || $this->publisherLedgerEntryId !== null
                || $this->reservationId !== null
            ) {
                throw new InvalidArgumentException('Accrued CPM allocation cannot contain an integer settlement.');
            }

            return;
        }

        if ($this->status === CpmBillingAllocationStatus::Billed) {
            if (
                ($this->grossPoints === 0 && $this->publisherPoints === 0)
                || $this->assessedGrossPoints !== $this->grossPoints
                || $this->reason !== null
                || ($this->grossPoints === 0 && ($this->advertiserLedgerEntryId !== null || $this->reservationId !== null))
                || ($this->grossPoints > 0 && ($this->advertiserLedgerEntryId === null || $this->reservationId === null || trim($this->reservationId) === ''))
                || ($this->publisherPoints === 0 && $this->publisherLedgerEntryId !== null)
                || ($this->publisherPoints > 0 && $this->publisherLedgerEntryId === null)
            ) {
                throw new InvalidArgumentException('Billed CPM allocation settlement state is invalid.');
            }

            return;
        }

        if (
            $this->reason === null
            || trim($this->reason) === ''
            || $this->grossPoints !== 0
            || $this->publisherPoints !== 0
            || $this->platformPoints !== 0
            || $this->grossRemainderBefore !== $this->grossRemainderAfter
            || $this->publisherShareRemainderBefore !== $this->publisherShareRemainderAfter
            || $this->advertiserLedgerEntryId !== null
            || $this->publisherLedgerEntryId !== null
            || $this->reservationId !== null
        ) {
            throw new InvalidArgumentException('Skipped CPM allocation must not mutate settlement state.');
        }
    }
}
