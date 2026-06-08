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
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;

final class CampaignBudgetService implements CampaignSpendEligibilityInterface
{
    public function __construct(
        private readonly CampaignBudgetRepositoryInterface $budgets,
        private readonly PointsLedgerService $ledger,
        private readonly PointsLedgerRepositoryInterface $ledgerRepository,
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
        $reservationId = trim($reservationId);
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('Spend reservation TTL seconds must be positive.');
        }

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
            return SpendReservationResult::rejected($rejection);
        }

        $availableBalance = $this->ledgerRepository->balanceForOrganization($organizationId)
            - $this->budgets->activeReservedSpendForOrganization($organizationId, $reservedAt);
        if ($availableBalance < $pointsAmount) {
            return SpendReservationResult::rejected(SpendFailureReason::InsufficientBalance);
        }

        return SpendReservationResult::accepted($this->budgets->createReservation(new SpendReservation(
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
        )));
    }

    public function rejectionReason(int $organizationId, int $campaignId, int $pointsAmount, DateTimeImmutable $at): ?SpendFailureReason
    {
        $caps = $this->budgets->findCaps($organizationId, $campaignId)
            ?? new CampaignBudgetCaps($campaignId, $organizationId, null, null, null);
        $rejection = $this->capRejection($caps, $pointsAmount, $at);
        if ($rejection !== null) {
            return $rejection;
        }

        $availableBalance = $this->ledgerRepository->balanceForOrganization($organizationId)
            - $this->budgets->activeReservedSpendForOrganization($organizationId, $at);

        return $availableBalance < $pointsAmount ? SpendFailureReason::InsufficientBalance : null;
    }

    public function commit(string $reservationId, DateTimeImmutable $committedAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        if ($reservation->status === SpendReservationStatus::Committed) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status === SpendReservationStatus::Expired || $committedAt > $reservation->expiresAt) {
            if ($reservation->status === SpendReservationStatus::Reserved) {
                $reservation = $this->budgets->markExpired($reservation->reservationId, $committedAt) ?? $reservation;
            }

            return SpendReservationResult::rejected(SpendFailureReason::ExpiredReservation, $reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        if ($this->ledgerRepository->balanceForOrganization($reservation->organizationId) < $reservation->pointsAmount) {
            return SpendReservationResult::rejected(SpendFailureReason::InsufficientBalance, $reservation);
        }

        $entry = $this->ledger->debit(
            organizationId: $reservation->organizationId,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: $reservation->pointsAmount,
            idempotencyKey: 'spend_reservation:' . $reservation->reservationId . ':commit',
            referenceType: 'spend_reservation',
            referenceId: $reservation->id,
            memo: 'Campaign spend reservation committed',
            metadata: [
                'campaign_id' => $reservation->campaignId,
                'reservation_id' => $reservation->reservationId,
            ],
        );

        return SpendReservationResult::accepted(
            $this->budgets->markCommitted($reservation->reservationId, (int) $entry->id, $committedAt) ?? $reservation,
        );
    }

    public function release(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        if ($reservation->status === SpendReservationStatus::Released) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        return SpendReservationResult::accepted(
            $this->budgets->markReleased($reservation->reservationId, $releasedAt) ?? $reservation,
        );
    }

    public function expire(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationResult
    {
        $reservation = $this->budgets->findReservation($reservationId);
        if ($reservation === null) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState);
        }

        if ($reservation->status === SpendReservationStatus::Expired) {
            return SpendReservationResult::accepted($reservation);
        }

        if ($reservation->status !== SpendReservationStatus::Reserved) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        if ($expiredAt <= $reservation->expiresAt) {
            return SpendReservationResult::rejected(SpendFailureReason::DuplicateState, $reservation);
        }

        return SpendReservationResult::accepted(
            $this->budgets->markExpired($reservation->reservationId, $expiredAt) ?? $reservation,
        );
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
}
