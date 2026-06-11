<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Billing\AdEventBillingResult;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Service\CampaignBudgetService;

final readonly class AdEventBillingService
{
    private const RESERVATION_TTL_SECONDS = 300;

    public function __construct(
        private CampaignBudgetService $budgets,
        private RevenueShareService $revenueShare,
        private ?Connection $connection = null,
    ) {
    }

    public function bill(BillableAdEvent $event): AdEventBillingResult
    {
        if ($this->connection === null) {
            return $this->billWithinTransaction($event);
        }

        return $this->connection->transactional(fn (): AdEventBillingResult => $this->billWithinTransaction($event));
    }

    private function billWithinTransaction(BillableAdEvent $event): AdEventBillingResult
    {
        if (!$event->valid) {
            return AdEventBillingResult::skipped('invalid_event');
        }

        if ($event->costPoints <= 0) {
            return AdEventBillingResult::skipped('zero_cost');
        }

        $revenueShareRejection = $this->revenueShare->rejectionReasonForAdEvent(
            eventId: $this->publisherEventId($event),
            publisherOrganizationId: $event->publisherOrganizationId,
            siteId: $event->siteId,
            adSlotId: $event->slotId,
            grossPoints: $event->costPoints,
        );
        if ($revenueShareRejection !== null) {
            return AdEventBillingResult::skipped($revenueShareRejection);
        }

        $reservationId = $this->reservationId($event);
        $reservation = $this->budgets->reserve(
            organizationId: $event->advertiserOrganizationId,
            campaignId: $event->campaignId,
            reservationId: $reservationId,
            pointsAmount: $event->costPoints,
            reservedAt: $event->occurredAt,
            ttlSeconds: self::RESERVATION_TTL_SECONDS,
        );
        if (!$reservation->accepted) {
            return AdEventBillingResult::skipped($reservation->failureReason?->value ?? 'budget_rejected');
        }

        $committed = $this->budgets->commit($reservationId, $event->occurredAt);
        if (!$committed->accepted) {
            return AdEventBillingResult::skipped($committed->failureReason?->value ?? 'budget_commit_rejected');
        }

        $earning = $this->revenueShare->creditForAdEvent(
            eventId: $this->publisherEventId($event),
            publisherOrganizationId: $event->publisherOrganizationId,
            siteId: $event->siteId,
            adSlotId: $event->slotId,
            advertiserOrganizationId: $event->advertiserOrganizationId,
            campaignId: $event->campaignId,
            grossPoints: $event->costPoints,
            earnedAt: $event->occurredAt,
        );

        return AdEventBillingResult::billed(
            grossPoints: $event->costPoints,
            publisherPoints: $earning->publisherPoints,
            duplicate: $committed->reservation?->ledgerEntryId !== null && $reservation->reservation?->status->value === 'committed',
        );
    }

    public function billServingEvent(AdEvent $event): AdEventBillingResult
    {
        if (
            $event->publisherOrganizationId === null
            || $event->advertiserOrganizationId === null
            || $event->campaignId === null
            || $event->adId === null
            || $event->costPoints === null
        ) {
            return AdEventBillingResult::skipped('missing_billing_metadata');
        }

        return $this->bill(new BillableAdEvent(
            eventType: $event->eventType,
            eventId: $event->eventId,
            decisionId: $event->decisionId,
            siteId: $event->siteId,
            slotId: $event->slotId,
            publisherOrganizationId: $event->publisherOrganizationId,
            advertiserOrganizationId: $event->advertiserOrganizationId,
            campaignId: $event->campaignId,
            adId: $event->adId,
            viewerId: $event->viewerId,
            costPoints: $event->costPoints,
            valid: $event->valid,
            occurredAt: $event->occurredAt,
        ));
    }

    private function reservationId(BillableAdEvent $event): string
    {
        return 'ad-event:' . $event->eventType . ':' . trim($event->decisionId) . ':' . trim($event->eventId);
    }

    private function publisherEventId(BillableAdEvent $event): string
    {
        return $event->eventType . ':' . trim($event->decisionId) . ':' . trim($event->eventId);
    }
}
