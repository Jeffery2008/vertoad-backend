<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationResult;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\Campaign\CampaignRepositoryInterface;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;

final class CampaignBudgetService implements CampaignSpendEligibilityInterface
{
    public function __construct(
        private readonly CampaignBudgetRepositoryInterface $budgets,
        private readonly PointsLedgerService $ledger,
        private readonly PointsLedgerRepositoryInterface $ledgerRepository,
        private readonly ?CampaignRepositoryInterface $campaigns = null,
    ) {
    }

    public function saveCaps(CampaignBudgetCaps $caps): CampaignBudgetCaps
    {
        return $this->budgets->saveCaps($caps);
    }

    public function reserve(
        int $organizationId,
        int $campaignId,
        string $reservationId,
        int $pointsAmount,
        DateTimeImmutable $reservedAt,
        int $ttlSeconds,
    ): SpendReservationResult {
        return $this->budgets->transactional(fn (): SpendReservationResult => $this->reserveWithinTransaction(
            $organizationId,
            $campaignId,
            $reservationId,
            $pointsAmount,
            $reservedAt,
            $ttlSeconds,
        ));
    }

    private function reserveWithinTransaction(
        int $organizationId,
        int $campaignId,
        string $reservationId,
        int $pointsAmount,
        DateTimeImmutable $reservedAt,
        int $ttlSeconds,
    ): SpendReservationResult {
        $reservationId = trim($reservationId);
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('Spend reservation TTL seconds must be positive.');
        }

        $this->budgets->lockBudgetScope($organizationId, $campaignId);

        $existing = $this->budgets->findReservation($reservationId);
        if ($existing !== null) {
            return $this->sameReservation($existing, $organizationId, $campaignId, $pointsAmount)
                ? SpendReservationResult::accepted($existing)
                : SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $existing);
        }

        $caps = $this->budgets->findCaps($organizationId, $campaignId)
            ?? new CampaignBudgetCaps($campaignId, $organizationId, null, null, null);

        $expiresAt = $reservedAt->modify('+' . $ttlSeconds . ' seconds');
        $rejection = $this->capRejection($caps, $pointsAmount, $reservedAt);
        if ($rejection !== null) {
            $this->pauseCampaignForTerminalRejection($organizationId, $campaignId, $rejection);

            return SpendReservationResult::rejected($rejection);
        }

        $availableBalance = $this->ledgerRepository->balanceForOrganization($organizationId)
            - $this->budgets->activeReservedSpendForOrganization($organizationId, $reservedAt);
        if ($availableBalance < $pointsAmount) {
            $this->pauseCampaignForTerminalRejection($organizationId, $campaignId, SpendFailureReason::InsufficientBalance);

            return SpendReservationResult::rejected(SpendFailureReason::InsufficientBalance);
        }

        $created = $this->budgets->createReservation(new SpendReservation(
            id: null,
            reservationId: $reservationId,
            organizationId: $organizationId,
            campaignId: $campaignId,
            pointsAmount: $pointsAmount,
            status: SpendReservationStatus::Reserved,
            reservedAt: $reservedAt,
            expiresAt: $expiresAt,
            committedAt: null,
            releasedAt: null,
            expiredAt: null,
            ledgerEntryId: null,
        ));

        return $this->sameReservation($created, $organizationId, $campaignId, $pointsAmount)
            ? SpendReservationResult::accepted($created)
            : SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $created);
    }

    public function rejectionReason(int $organizationId, int $campaignId, int $pointsAmount, DateTimeImmutable $at): ?SpendFailureReason
    {
        return $this->budgets->transactional(fn (): ?SpendFailureReason => $this->rejectionReasonWithinTransaction(
            $organizationId,
            $campaignId,
            $pointsAmount,
            $at,
        ));
    }

    private function rejectionReasonWithinTransaction(int $organizationId, int $campaignId, int $pointsAmount, DateTimeImmutable $at): ?SpendFailureReason
    {
        $this->budgets->lockBudgetScope($organizationId, $campaignId);
        $caps = $this->budgets->findCaps($organizationId, $campaignId)
            ?? new CampaignBudgetCaps($campaignId, $organizationId, null, null, null);
        $rejection = $this->capRejection($caps, $pointsAmount, $at);
        if ($rejection !== null) {
            $this->pauseCampaignForTerminalRejection($organizationId, $campaignId, $rejection);

            return $rejection;
        }

        $availableBalance = $this->ledgerRepository->balanceForOrganization($organizationId)
            - $this->budgets->activeReservedSpendForOrganization($organizationId, $at);

        if ($availableBalance < $pointsAmount) {
            $this->pauseCampaignForTerminalRejection($organizationId, $campaignId, SpendFailureReason::InsufficientBalance);

            return SpendFailureReason::InsufficientBalance;
        }

        return null;
    }

    public function commit(string $reservationId, DateTimeImmutable $committedAt): SpendReservationResult
    {
        try {
            return $this->budgets->transactional(fn (): SpendReservationResult => $this->commitWithinTransaction($reservationId, $committedAt));
        } catch (SpendReservationTransitionConflictException $exception) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $exception->reservation);
        }
    }

    private function commitWithinTransaction(string $reservationId, DateTimeImmutable $committedAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        $this->budgets->lockBudgetScope($reservation->organizationId, $reservation->campaignId);
        $reservation = $this->budgets->findReservation($reservationId) ?? $reservation;

        if ($reservation->status === SpendReservationStatus::Committed) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status === SpendReservationStatus::Expired || $committedAt > $reservation->expiresAt) {
            if ($reservation->status === SpendReservationStatus::Reserved) {
                $transition = $this->budgets->markExpired($reservation->reservationId, $committedAt);
                $reservation = $transition->reservation ?? $reservation;
            }

            return SpendReservationResult::rejected(SpendFailureReason::ExpiredReservation, $reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        $entry = $this->ledgerRepository->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: $reservation->organizationId,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: $reservation->pointsAmount,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: 'spend_reservation',
            referenceId: $reservation->id,
            idempotencyKey: 'spend_reservation:' . $reservation->reservationId . ':commit',
            memo: 'Campaign spend reservation committed',
            metadata: [
                'campaign_id' => $reservation->campaignId,
                'reservation_id' => $reservation->reservationId,
            ],
        ));
        if ($entry === null) {
            $this->pauseCampaign($reservation->organizationId, $reservation->campaignId, 'insufficient_balance');

            return SpendReservationResult::rejected(SpendFailureReason::InsufficientBalance, $reservation);
        }

        $transition = $this->budgets->markCommitted($reservation->reservationId, (int) $entry->id, $committedAt);
        $committed = $transition->reservation ?? $reservation;
        if (!$transition->changed) {
            throw new SpendReservationTransitionConflictException($committed);
        }

        $this->pauseIfTerminalBudgetExhausted($committed, $committedAt);

        return SpendReservationResult::accepted($committed);
    }

    public function release(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationResult
    {
        return $this->budgets->transactional(fn (): SpendReservationResult => $this->releaseWithinTransaction($reservationId, $releasedAt));
    }

    private function releaseWithinTransaction(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        $this->budgets->lockBudgetScope($reservation->organizationId, $reservation->campaignId);
        $reservation = $this->budgets->findReservation($reservationId) ?? $reservation;

        if ($reservation->status === SpendReservationStatus::Released) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        $transition = $this->budgets->markReleased($reservation->reservationId, $releasedAt);

        return $transition->changed
            ? SpendReservationResult::accepted($transition->reservation ?? $reservation)
            : SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $transition->reservation ?? $reservation);
    }

    public function expire(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationResult
    {
        return $this->budgets->transactional(fn (): SpendReservationResult => $this->expireWithinTransaction($reservationId, $expiredAt));
    }

    private function expireWithinTransaction(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        $this->budgets->lockBudgetScope($reservation->organizationId, $reservation->campaignId);
        $reservation = $this->budgets->findReservation($reservationId) ?? $reservation;

        if ($reservation->status === SpendReservationStatus::Expired) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        if ($expiredAt <= $reservation->expiresAt) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        $transition = $this->budgets->markExpired($reservation->reservationId, $expiredAt);

        return $transition->changed
            ? SpendReservationResult::accepted($transition->reservation ?? $reservation)
            : SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $transition->reservation ?? $reservation);
    }

    private function capRejection(
        CampaignBudgetCaps $caps,
        int $pointsAmount,
        DateTimeImmutable $reservedAt,
    ): ?SpendFailureReason {
        $hourStart = $reservedAt->setTime((int) $reservedAt->format('H'), 0, 0);
        $dayStart = $reservedAt->setTime(0, 0, 0);

        if (
            $caps->hourlyCapPoints !== null
            && $this->spentInWindow($caps, $hourStart, $hourStart->modify('+1 hour'), $reservedAt) + $pointsAmount > $caps->hourlyCapPoints
        ) {
            return SpendFailureReason::HourlyCap;
        }

        if (
            $caps->dailyCapPoints !== null
            && $this->spentInWindow($caps, $dayStart, $dayStart->modify('+1 day'), $reservedAt) + $pointsAmount > $caps->dailyCapPoints
        ) {
            return SpendFailureReason::DailyCap;
        }

        if (
            $caps->totalCapPoints !== null
            && $this->budgets->committedSpendForCampaign($caps->organizationId, $caps->campaignId)
                + $this->budgets->activeReservedSpendForCampaign($caps->organizationId, $caps->campaignId, $reservedAt)
                + $pointsAmount > $caps->totalCapPoints
        ) {
            return SpendFailureReason::TotalCap;
        }

        return null;
    }

    private function spentInWindow(
        CampaignBudgetCaps $caps,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeImmutable $reservedAt,
    ): int {
        return $this->budgets->committedSpendForCampaignWindow($caps->organizationId, $caps->campaignId, $windowStart, $windowEnd)
            + $this->budgets->activeReservedSpendForCampaignWindow(
                $caps->organizationId,
                $caps->campaignId,
                $windowStart,
                $windowEnd,
                $reservedAt,
            );
    }

    private function sameReservation(
        SpendReservation $reservation,
        int $organizationId,
        int $campaignId,
        int $pointsAmount,
    ): bool {
        return $reservation->organizationId === $organizationId
            && $reservation->campaignId === $campaignId
            && $reservation->pointsAmount === $pointsAmount;
    }

    private function pauseCampaignForTerminalRejection(
        int $organizationId,
        int $campaignId,
        SpendFailureReason $reason,
    ): void {
        $this->pauseCampaign($organizationId, $campaignId, $reason->value);
    }

    private function pauseIfTerminalBudgetExhausted(SpendReservation $reservation, DateTimeImmutable $at): void
    {
        $caps = $this->budgets->findCaps($reservation->organizationId, $reservation->campaignId);
        if (
            $caps?->totalCapPoints !== null
            && $this->budgets->committedSpendForCampaign($reservation->organizationId, $reservation->campaignId) >= $caps->totalCapPoints
        ) {
            $this->pauseCampaign($reservation->organizationId, $reservation->campaignId, 'total_cap_exhausted');

            return;
        }

        $availableBalance = $this->ledgerRepository->balanceForOrganization($reservation->organizationId)
            - $this->budgets->activeReservedSpendForOrganization($reservation->organizationId, $at);
        if ($availableBalance <= 0) {
            $this->pauseCampaign($reservation->organizationId, $reservation->campaignId, 'balance_exhausted');
        }
    }

    private function pauseCampaign(int $organizationId, int $campaignId, string $reason): void
    {
        $this->campaigns?->pauseIfActive($organizationId, $campaignId, $reason);
    }
}

final class SpendReservationTransitionConflictException extends \RuntimeException
{
    public function __construct(public readonly SpendReservation $reservation)
    {
        parent::__construct('spend_reservation_transition_conflict');
    }
}
