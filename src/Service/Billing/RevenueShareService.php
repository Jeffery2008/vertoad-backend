<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Billing\PublisherEarningEvent;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Service\PointsLedgerService;

final class RevenueShareService
{
    public function __construct(
        private readonly RevenueShareRepository $repository,
        private readonly PointsLedgerService $ledger,
    ) {
    }

    public function creditForAdEvent(
        string $eventId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $advertiserOrganizationId,
        ?int $campaignId,
        int $grossPoints,
        DateTimeImmutable $earnedAt,
    ): PublisherEarningEvent {
        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new InvalidArgumentException('Ad event id is required for publisher earnings.');
        }

        $existing = $this->repository->findEarningByEventId($eventId);
        if ($existing !== null) {
            return $existing;
        }

        $rule = $this->repository->findBestRule($publisherOrganizationId, $siteId, $adSlotId);
        $ratio = $rule?->shareRatioBps ?? 0;
        $publisherPoints = intdiv($grossPoints * $ratio, 10000);
        if ($publisherPoints <= 0) {
            throw new InvalidArgumentException('Publisher earning points must be positive.');
        }

        $ledgerEntry = $this->ledger->credit(
            organizationId: $publisherOrganizationId,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: $publisherPoints,
            idempotencyKey: 'publisher-earning:' . $eventId,
            referenceType: 'ad_event',
            referenceId: null,
            memo: 'Publisher earning for ad event ' . $eventId,
            metadata: [
                'ad_slot_id' => $adSlotId,
                'advertiser_organization_id' => $advertiserOrganizationId,
                'campaign_id' => $campaignId,
                'event_id' => $eventId,
                'gross_points' => $grossPoints,
                'revenue_share_rule_id' => $rule?->id,
                'share_ratio_bps' => $ratio,
                'site_id' => $siteId,
            ],
        );

        return $this->repository->createEarning(
            eventId: $eventId,
            publisherOrganizationId: $publisherOrganizationId,
            siteId: $siteId,
            adSlotId: $adSlotId,
            advertiserOrganizationId: $advertiserOrganizationId,
            campaignId: $campaignId,
            grossPoints: $grossPoints,
            shareRatioBps: $ratio,
            publisherPoints: $publisherPoints,
            revenueShareRuleId: $rule?->id,
            ledgerEntryId: $ledgerEntry->id ?? 0,
            metadata: [
                'rule_scope' => $rule?->scope,
                'rule_version' => $rule?->version,
            ],
            earnedAt: $earnedAt,
        );
    }
}
