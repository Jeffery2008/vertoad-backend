<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendReservation;

interface CampaignBudgetRepositoryInterface
{
    public function saveCaps(CampaignBudgetCaps $caps): CampaignBudgetCaps;

    public function findCaps(int $organizationId, int $campaignId): ?CampaignBudgetCaps;

    public function findReservation(string $reservationId): ?SpendReservation;

    public function createReservation(SpendReservation $reservation): SpendReservation;

    public function markCommitted(string $reservationId, int $ledgerEntryId, DateTimeImmutable $committedAt): ?SpendReservation;

    public function markReleased(string $reservationId, DateTimeImmutable $releasedAt): ?SpendReservation;

    public function markExpired(string $reservationId, DateTimeImmutable $expiredAt): ?SpendReservation;

    public function activeReservedSpendForCampaign(int $organizationId, int $campaignId, DateTimeImmutable $at): int;

    public function committedSpendForCampaign(int $organizationId, int $campaignId): int;

    public function activeReservedSpendForCampaignWindow(
        int $organizationId,
        int $campaignId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeImmutable $at,
    ): int;

    public function committedSpendForCampaignWindow(
        int $organizationId,
        int $campaignId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): int;

    public function activeReservedSpendForOrganization(int $organizationId, DateTimeImmutable $at): int;
}
