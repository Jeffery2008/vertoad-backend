<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Billing\AdEventBillingResult;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationCalculation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingStream;
use VertoAD\Domain\Billing\CpmRevenueShareSnapshot;
use VertoAD\Repository\Billing\CpmBillingRepositoryInterface;
use VertoAD\Service\CampaignBudgetService;

final class CpmBillingService implements CpmChargeEstimatorInterface
{
    private const int RESERVATION_TTL_SECONDS = 300;

    public function __construct(
        private readonly CpmBillingRepositoryInterface $repository,
        private readonly CampaignBudgetService $budgets,
        private readonly RevenueShareService $revenueShare,
    ) {
    }

    public function bill(BillableAdEvent $event): AdEventBillingResult
    {
        if ($event->eventType !== 'impression') {
            throw new InvalidArgumentException('CPM billing only accepts impression events.');
        }

        return $this->repository->transactional(fn (): AdEventBillingResult => $this->billWithinTransaction($event));
    }

    public function nextChargePoints(
        int $advertiserOrganizationId,
        int $campaignId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $bidPointsPerThousand,
    ): int {
        if ($bidPointsPerThousand <= 0) {
            return 0;
        }

        $rule = $this->revenueShare->findRuleForAdEvent($publisherOrganizationId, $siteId, $adSlotId);
        if ($rule === null) {
            throw new CpmBillingUnavailableException('missing_revenue_share_rule');
        }
        if ($rule->shareRatioBps <= 0) {
            throw new CpmBillingUnavailableException('zero_publisher_earning');
        }

        $stream = new CpmBillingStream(
            $advertiserOrganizationId,
            $campaignId,
            $publisherOrganizationId,
            $siteId,
            $adSlotId,
        );
        $accumulator = $this->repository->findAccumulator($stream)
            ?? new CpmBillingAccumulator(id: null, stream: $stream);

        return $accumulator->allocate($bidPointsPerThousand, $rule->shareRatioBps)->grossPoints;
    }

    private function billWithinTransaction(BillableAdEvent $event): AdEventBillingResult
    {
        $eventKey = $this->eventKey($event);
        $existing = $this->repository->findAllocation($eventKey);
        if ($existing !== null) {
            $this->assertReplayMatches($existing, $event);
            return $this->resultFromAllocation($existing, duplicate: true);
        }
        if (!$event->valid) {
            return AdEventBillingResult::skipped('invalid_event');
        }
        if ($event->costPoints <= 0) {
            return AdEventBillingResult::skipped('zero_cost');
        }

        $rule = $this->revenueShare->findRuleForAdEvent(
            $event->publisherOrganizationId,
            $event->siteId,
            $event->slotId,
        );
        $stream = $this->stream($event);
        $revenueShare = $rule === null
            ? CpmRevenueShareSnapshot::missing()
            : CpmRevenueShareSnapshot::fromRule($rule);
        $claimResult = $this->repository->claimAllocation($this->processingAllocation(
            $event,
            $eventKey,
            $stream,
            $revenueShare,
        ));
        $claim = $claimResult->allocation;
        $this->assertReplayMatches($claim, $event);
        if (!$claimResult->acquired) {
            return $this->resultFromAllocation($claim, duplicate: true);
        }

        if ($rule === null) {
            return $this->recordSkipped(
                $event,
                $eventKey,
                $stream,
                'missing_revenue_share_rule',
                $claim,
            );
        }
        if ($rule->shareRatioBps <= 0) {
            return $this->recordSkipped(
                $event,
                $eventKey,
                $stream,
                'zero_publisher_earning',
                $claim,
            );
        }

        $accumulator = $this->repository->lockAccumulator($stream);

        $calculation = $accumulator->allocate($event->costPoints, $rule->shareRatioBps);
        if ($calculation->grossPoints === 0 && $calculation->publisherPoints === 0) {
            $saved = $this->repository->saveAccumulator($calculation->nextAccumulator);
            $allocation = $this->repository->completeAllocation($this->allocation(
                event: $event,
                eventKey: $eventKey,
                stream: $stream,
                claim: $claim,
                accumulator: $saved,
                calculation: $calculation,
                status: CpmBillingAllocationStatus::Accrued,
                reason: 'cpm_fraction_accumulated',
                assessedGrossPoints: 0,
                grossPoints: 0,
                publisherPoints: 0,
                platformPoints: 0,
                advertiserLedgerEntryId: null,
                publisherLedgerEntryId: null,
                reservationId: null,
            ));

            return $this->resultFromAllocation($allocation, duplicate: false);
        }

        $reservationId = null;
        $advertiserLedgerEntryId = null;
        if ($calculation->grossPoints > 0) {
            $reservationId = 'cpm-spend:' . $eventKey;
            $reservation = $this->budgets->reserve(
                organizationId: $event->advertiserOrganizationId,
                campaignId: $event->campaignId,
                reservationId: $reservationId,
                pointsAmount: $calculation->grossPoints,
                reservedAt: $event->occurredAt,
                ttlSeconds: self::RESERVATION_TTL_SECONDS,
            );
            if (!$reservation->accepted) {
                return $this->recordSkipped(
                    $event,
                    $eventKey,
                    $stream,
                    $reservation->failureReason?->value ?? 'budget_rejected',
                    $claim,
                    $accumulator,
                    $calculation,
                );
            }

            $committed = $this->budgets->commit($reservationId, $event->occurredAt);
            if (!$committed->accepted) {
                return $this->recordSkipped(
                    $event,
                    $eventKey,
                    $stream,
                    $committed->failureReason?->value ?? 'budget_commit_rejected',
                    $claim,
                    $accumulator,
                    $calculation,
                );
            }
            $advertiserLedgerEntryId = $committed->reservation?->ledgerEntryId;
        }

        $earning = $this->revenueShare->creditForCpmAllocation(
            eventId: 'cpm:' . $eventKey,
            publisherOrganizationId: $event->publisherOrganizationId,
            siteId: $event->siteId,
            adSlotId: $event->slotId,
            advertiserOrganizationId: $event->advertiserOrganizationId,
            campaignId: $event->campaignId,
            grossPoints: $calculation->grossPoints,
            publisherPoints: $calculation->publisherPoints,
            rule: $rule,
            earnedAt: $event->occurredAt,
            metadata: [
                'cpm_bid_points_per_thousand' => $event->costPoints,
                'cpm_event_key' => $eventKey,
                'cpm_publisher_share_denominator' => CpmBillingAccumulator::PUBLISHER_SHARE_DENOMINATOR,
                'cpm_publisher_share_remainder_after' => $calculation->publisherShareRemainderAfter,
                'cpm_publisher_share_remainder_before' => $calculation->publisherShareRemainderBefore,
            ],
        );

        $saved = $this->repository->saveAccumulator($calculation->nextAccumulator);
        $allocation = $this->repository->completeAllocation($this->allocation(
            event: $event,
            eventKey: $eventKey,
            stream: $stream,
            claim: $claim,
            accumulator: $saved,
            calculation: $calculation,
            status: CpmBillingAllocationStatus::Billed,
            reason: null,
            assessedGrossPoints: $calculation->grossPoints,
            grossPoints: $calculation->grossPoints,
            publisherPoints: $calculation->publisherPoints,
            platformPoints: $calculation->platformPoints,
            advertiserLedgerEntryId: $advertiserLedgerEntryId,
            publisherLedgerEntryId: $earning?->id,
            reservationId: $reservationId,
        ));

        return $this->resultFromAllocation($allocation, duplicate: false);
    }

    private function recordSkipped(
        BillableAdEvent $event,
        string $eventKey,
        CpmBillingStream $stream,
        string $reason,
        CpmBillingAllocation $claim,
        ?CpmBillingAccumulator $accumulator = null,
        ?CpmBillingAllocationCalculation $calculation = null,
    ): AdEventBillingResult {
        $grossRemainder = $accumulator?->grossRemainderMilliPoints ?? 0;
        $publisherRemainder = $accumulator?->publisherShareRemainderNumerator ?? 0;
        $allocation = $this->repository->completeAllocation(new CpmBillingAllocation(
            id: $claim->id,
            eventKey: $eventKey,
            eventType: 'impression',
            eventId: trim($event->eventId),
            decisionId: trim($event->decisionId),
            stream: $stream,
            revenueShare: $claim->revenueShare,
            accumulatorId: $accumulator?->id,
            bidPointsPerThousand: $event->costPoints,
            assessedGrossPoints: $calculation?->grossPoints ?? 0,
            status: CpmBillingAllocationStatus::Skipped,
            reason: $reason,
            grossRemainderBefore: $grossRemainder,
            grossPoints: 0,
            grossRemainderAfter: $grossRemainder,
            publisherShareRemainderBefore: $publisherRemainder,
            publisherPoints: 0,
            publisherShareRemainderAfter: $publisherRemainder,
            platformPoints: 0,
            advertiserLedgerEntryId: null,
            publisherLedgerEntryId: null,
            reservationId: null,
            occurredAt: $event->occurredAt,
            processedAt: $this->now(),
        ));

        return $this->resultFromAllocation($allocation, duplicate: false);
    }

    private function allocation(
        BillableAdEvent $event,
        string $eventKey,
        CpmBillingStream $stream,
        CpmBillingAllocation $claim,
        CpmBillingAccumulator $accumulator,
        CpmBillingAllocationCalculation $calculation,
        CpmBillingAllocationStatus $status,
        ?string $reason,
        int $assessedGrossPoints,
        int $grossPoints,
        int $publisherPoints,
        int $platformPoints,
        ?int $advertiserLedgerEntryId,
        ?int $publisherLedgerEntryId,
        ?string $reservationId,
    ): CpmBillingAllocation {
        return new CpmBillingAllocation(
            id: $claim->id,
            eventKey: $eventKey,
            eventType: 'impression',
            eventId: trim($event->eventId),
            decisionId: trim($event->decisionId),
            stream: $stream,
            revenueShare: $claim->revenueShare,
            accumulatorId: $accumulator->id,
            bidPointsPerThousand: $event->costPoints,
            assessedGrossPoints: $assessedGrossPoints,
            status: $status,
            reason: $reason,
            grossRemainderBefore: $calculation->grossRemainderBefore,
            grossPoints: $grossPoints,
            grossRemainderAfter: $calculation->grossRemainderAfter,
            publisherShareRemainderBefore: $calculation->publisherShareRemainderBefore,
            publisherPoints: $publisherPoints,
            publisherShareRemainderAfter: $calculation->publisherShareRemainderAfter,
            platformPoints: $platformPoints,
            advertiserLedgerEntryId: $advertiserLedgerEntryId,
            publisherLedgerEntryId: $publisherLedgerEntryId,
            reservationId: $reservationId,
            occurredAt: $event->occurredAt,
            processedAt: $this->now(),
        );
    }

    private function resultFromAllocation(CpmBillingAllocation $allocation, bool $duplicate): AdEventBillingResult
    {
        return match ($allocation->status) {
            CpmBillingAllocationStatus::Processing => throw new RuntimeException('CPM allocation is still processing.'),
            CpmBillingAllocationStatus::Accrued => AdEventBillingResult::accrued($duplicate),
            CpmBillingAllocationStatus::Billed => AdEventBillingResult::billed(
                $allocation->grossPoints,
                $allocation->publisherPoints,
                $duplicate,
            ),
            CpmBillingAllocationStatus::Skipped => AdEventBillingResult::skipped(
                $allocation->reason ?? 'cpm_billing_skipped',
                $duplicate,
            ),
        };
    }

    private function stream(BillableAdEvent $event): CpmBillingStream
    {
        return new CpmBillingStream(
            $event->advertiserOrganizationId,
            $event->campaignId,
            $event->publisherOrganizationId,
            $event->siteId,
            $event->slotId,
        );
    }

    private function processingAllocation(
        BillableAdEvent $event,
        string $eventKey,
        CpmBillingStream $stream,
        CpmRevenueShareSnapshot $revenueShare,
    ): CpmBillingAllocation {
        return new CpmBillingAllocation(
            id: null,
            eventKey: $eventKey,
            eventType: 'impression',
            eventId: trim($event->eventId),
            decisionId: trim($event->decisionId),
            stream: $stream,
            revenueShare: $revenueShare,
            accumulatorId: null,
            bidPointsPerThousand: $event->costPoints,
            assessedGrossPoints: 0,
            status: CpmBillingAllocationStatus::Processing,
            reason: null,
            grossRemainderBefore: 0,
            grossPoints: 0,
            grossRemainderAfter: 0,
            publisherShareRemainderBefore: 0,
            publisherPoints: 0,
            publisherShareRemainderAfter: 0,
            platformPoints: 0,
            advertiserLedgerEntryId: null,
            publisherLedgerEntryId: null,
            reservationId: null,
            occurredAt: $event->occurredAt,
            processedAt: null,
        );
    }

    private function eventKey(BillableAdEvent $event): string
    {
        $decisionId = trim($event->decisionId);
        $eventId = trim($event->eventId);

        return hash('sha256', "cpm-v1\0"
            . pack('N', strlen($decisionId)) . $decisionId
            . pack('N', strlen($eventId)) . $eventId);
    }

    private function assertReplayMatches(CpmBillingAllocation $allocation, BillableAdEvent $event): void
    {
        if (
            $allocation->eventId !== trim($event->eventId)
            || $allocation->decisionId !== trim($event->decisionId)
            || $allocation->stream->advertiserOrganizationId !== $event->advertiserOrganizationId
            || $allocation->stream->campaignId !== $event->campaignId
            || $allocation->stream->publisherOrganizationId !== $event->publisherOrganizationId
            || $allocation->stream->siteId !== $event->siteId
            || $allocation->stream->adSlotId !== $event->slotId
            || $allocation->bidPointsPerThousand !== $event->costPoints
            || !$event->valid
            || $this->eventTime($allocation->occurredAt) !== $this->eventTime($event->occurredAt)
        ) {
            throw new InvalidArgumentException('CPM billing event replay conflicts with the original allocation.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function eventTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
