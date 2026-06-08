<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
use VertoAD\Service\Serving\AdServingService;

final class AdServingServiceTest extends TestCase
{
    public function testServeReturnsDeterministicNoFillWhenNoEligibleAdExists(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertFalse($decision->filled);
        self::assertSame('no_eligible_ad', $decision->reason);
        self::assertSame('no-fill:10:20:viewer-1', $decision->decisionId);
        self::assertStringContainsString('data-vertoad-no-fill="1"', $decision->iframeHtml);
    }

    public function testServeRejectsUnverifiedInventoryAndUnsafeLandingUrlWithoutThrowing(): void
    {
        $unsafeCandidate = new AdCandidate(
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            creativeHtml: '<strong>Unsafe</strong>',
            landingUrl: 'javascript:alert(1)',
            width: 300,
            height: 250,
            impressionCostPoints: 10,
            clickCostPoints: 20,
        );
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$unsafeCandidate]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $unverified = $service->serve(99, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        self::assertFalse($unverified->filled);
        self::assertSame('unverified_inventory', $unverified->reason);

        $unsafe = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        self::assertFalse($unsafe->filled);
        self::assertSame('unsafe_landing_url', $unsafe->reason);
    }

    public function testTrackRequiresValidViewabilityThresholdAndDeduplicatesEvents(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        self::assertSame(42, $decision->publisherOrganizationId);
        self::assertSame(10, $decision->impressionCostPoints);
        self::assertSame(20, $decision->clickCostPoints);

        $tooLow = $service->trackImpression($decision->decisionId, 'viewer-1', 0.49, 1000, 'evt-1', new DateTimeImmutable('2026-06-08 10:00:02'));
        self::assertFalse($tooLow->accepted);
        self::assertFalse($tooLow->duplicate);
        self::assertSame('viewability_threshold_not_met', $tooLow->reason);

        $accepted = $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'evt-2', new DateTimeImmutable('2026-06-08 10:00:03'));
        self::assertTrue($accepted->accepted);
        self::assertFalse($accepted->duplicate);

        $duplicate = $service->trackImpression($decision->decisionId, 'viewer-1', 0.1, 10, 'evt-2', new DateTimeImmutable('2026-06-08 10:00:04'));
        self::assertTrue($duplicate->accepted);
        self::assertTrue($duplicate->duplicate);
        self::assertSame(1, $events->impressionCount());
    }

    public function testTrackRejectsUnknownOrMismatchedDecisions(): void
    {
        $decisions = new InMemoryAdDecisionRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            $decisions,
            new InMemoryAdEventRepository(),
        );
        $filled = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $noFill = $service->serve(10, 21, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        $missing = $service->trackImpression('missing', 'viewer-1', 0.5, 1000, 'evt-missing', new DateTimeImmutable('2026-06-08 10:00:00'));
        $wrongViewer = $service->trackImpression($filled->decisionId, 'viewer-2', 0.5, 1000, 'evt-wrong-viewer', new DateTimeImmutable('2026-06-08 10:00:01'));
        $noFillResult = $service->trackImpression($noFill->decisionId, 'viewer-1', 0.5, 1000, 'evt-no-fill', new DateTimeImmutable('2026-06-08 10:00:02'));

        self::assertFalse($missing->accepted);
        self::assertSame('decision_not_found', $missing->reason);
        self::assertFalse($wrongViewer->accepted);
        self::assertSame('decision_not_found', $wrongViewer->reason);
        self::assertFalse($noFillResult->accepted);
        self::assertSame('decision_not_found', $noFillResult->reason);
    }

    public function testAcceptedValidImpressionRecordsEventBufferMetadata(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $occurredAt = new DateTimeImmutable('2026-06-08 10:00:03');

        $result = $service->trackImpression($decision->decisionId, 'viewer-1', 0.75, 1500, 'imp-1', $occurredAt);

        self::assertTrue($result->accepted);
        $recorded = $events->events();
        self::assertCount(1, $recorded);
        self::assertSame('impression', $recorded[0]->eventType);
        self::assertSame('imp-1', $recorded[0]->eventId);
        self::assertSame($decision->decisionId, $recorded[0]->decisionId);
        self::assertSame(10, $recorded[0]->siteId);
        self::assertSame(20, $recorded[0]->slotId);
        self::assertSame('viewer-1', $recorded[0]->viewerId);
        self::assertSame('ad-1', $recorded[0]->adId);
        self::assertSame(30, $recorded[0]->campaignId);
        self::assertSame(40, $recorded[0]->advertiserOrganizationId);
        self::assertSame(42, $recorded[0]->publisherOrganizationId);
        self::assertSame(10, $recorded[0]->costPoints);
        self::assertSame($occurredAt, $recorded[0]->occurredAt);
        self::assertTrue($recorded[0]->valid);
        self::assertNull($recorded[0]->reason);
        self::assertSame(0.75, $recorded[0]->visibleRatio);
        self::assertSame(1500, $recorded[0]->visibleMs);
    }

    public function testClickRejectsMissingNoFillWrongViewerAndUnsafeLandingDecisions(): void
    {
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            $decisions,
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $noFill = $service->serve(10, 21, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        $missing = $service->recordClick('missing', 'viewer-1', 'clk-missing', new DateTimeImmutable('2026-06-08 10:00:05'));
        self::assertFalse($missing->accepted);
        self::assertSame('decision_not_found', $missing->reason);

        $noFillResult = $service->recordClick($noFill->decisionId, 'viewer-1', 'clk-no-fill', new DateTimeImmutable('2026-06-08 10:00:06'));
        self::assertFalse($noFillResult->accepted);
        self::assertSame('decision_not_found', $noFillResult->reason);

        $wrongViewer = $service->recordClick($decision->decisionId, 'viewer-2', 'clk-wrong-viewer', new DateTimeImmutable('2026-06-08 10:00:07'));
        self::assertFalse($wrongViewer->accepted);
        self::assertSame('decision_not_found', $wrongViewer->reason);

        $decisions->save($decision->withLandingUrl('http://127.0.0.1/admin'));
        $unsafe = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-unsafe', new DateTimeImmutable('2026-06-08 10:00:08'));
        self::assertFalse($unsafe->accepted);
        self::assertSame('unsafe_landing_url', $unsafe->reason);
    }

    public function testClickRequiresPriorValidImpression(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        $click = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:05'));
        self::assertFalse($click->accepted);
        self::assertSame('valid_impression_required', $click->reason);
        self::assertSame(0, $events->clickCount());
    }

    public function testClickRecordsAfterValidImpressionAndDeduplicatesByEventId(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'imp-1', new DateTimeImmutable('2026-06-08 10:00:02'));

        $click = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:35'));
        self::assertTrue($click->accepted);
        self::assertSame('https://advertiser.example/landing', $click->redirectUrl);
        self::assertSame(1, $events->clickCount());
        self::assertSame(20, $events->events()[1]->costPoints);

        $duplicate = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:06'));
        self::assertTrue($duplicate->accepted);
        self::assertTrue($duplicate->duplicate);
        self::assertSame('https://advertiser.example/landing', $duplicate->redirectUrl);
        self::assertSame(1, $events->clickCount());
    }

    public function testShortWindowRepeatClickIsRejectedAndRecordedInvalid(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'imp-1', new DateTimeImmutable('2026-06-08 10:00:02'));
        $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:35'));

        $repeat = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-2', new DateTimeImmutable('2026-06-08 10:00:45'));

        self::assertFalse($repeat->accepted);
        self::assertSame('repeat_click_window', $repeat->reason);
        self::assertSame(1, $events->clickCount());
        $recorded = $events->events();
        self::assertCount(3, $recorded);
        self::assertSame('click', $recorded[2]->eventType);
        self::assertSame('clk-2', $recorded[2]->eventId);
        self::assertFalse($recorded[2]->valid);
        self::assertSame('repeat_click_window', $recorded[2]->reason);
    }

    public function testDuplicateInvalidRepeatClickReplaysRejectedOutcome(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'imp-1', new DateTimeImmutable('2026-06-08 10:00:02'));
        $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:35'));
        $service->recordClick($decision->decisionId, 'viewer-1', 'clk-repeat', new DateTimeImmutable('2026-06-08 10:00:45'));

        $duplicateInvalid = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-repeat', new DateTimeImmutable('2026-06-08 10:00:46'));

        self::assertFalse($duplicateInvalid->accepted);
        self::assertSame('repeat_click_window', $duplicateInvalid->reason);
        self::assertNull($duplicateInvalid->redirectUrl);
        self::assertSame(1, $events->clickCount());
        self::assertCount(3, $events->events());
    }

    public function testServeRejectsMalformedAndUnsupportedLandingUrls(): void
    {
        foreach (['not-a-url', 'ftp://advertiser.example/landing'] as $landingUrl) {
            $service = new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository([new AdCandidate('ad-1', 30, 40, 'Creative', $landingUrl, 300, 250, 10, 20)]),
                new InMemoryAdDecisionRepository(),
                new InMemoryAdEventRepository(),
            );

            $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

            self::assertFalse($decision->filled);
            self::assertSame('unsafe_landing_url', $decision->reason);
        }
    }

    public function testServeSkipsBudgetRejectedCandidateAndUsesNextFundedSafeCandidate(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate('ad-expensive', 30, 40, 'https://advertiser.example/expensive', 100, 250),
                $this->candidate('ad-funded', 31, 41, 'https://advertiser.example/funded', 10, 20),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            new FixedCampaignSpendEligibility([
                30 => SpendFailureReason::InsufficientBalance,
                31 => null,
            ]),
        );

        $decision = $service->serve(10, 20, 'viewer-budget', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertTrue($decision->filled);
        self::assertSame('ad-funded', $decision->adId);
        self::assertSame(31, $decision->campaignId);
        self::assertSame(41, $decision->advertiserOrganizationId);
    }

    public function testServeNoFillsWhenEverySafeCandidateIsBudgetRejected(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->candidate('ad-expensive', 30, 40, 'https://advertiser.example/expensive', 100, 250)]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            new FixedCampaignSpendEligibility([30 => SpendFailureReason::DailyCap]),
        );

        $decision = $service->serve(10, 20, 'viewer-budget', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertFalse($decision->filled);
        self::assertSame('budget_daily_cap', $decision->reason);
    }

    private function safeCandidate(): AdCandidate
    {
        return $this->candidate('ad-1', 30, 40, 'https://advertiser.example/landing', 10, 20);
    }

    private function candidate(
        string $adId,
        int $campaignId,
        int $advertiserOrganizationId,
        string $landingUrl,
        int $impressionCostPoints,
        int $clickCostPoints,
    ): AdCandidate
    {
        return new AdCandidate(
            adId: $adId,
            campaignId: $campaignId,
            advertiserOrganizationId: $advertiserOrganizationId,
            creativeHtml: '<strong>VertoAD creative</strong>',
            landingUrl: $landingUrl,
            width: 300,
            height: 250,
            impressionCostPoints: $impressionCostPoints,
            clickCostPoints: $clickCostPoints,
        );
    }
}

final readonly class FixedCampaignSpendEligibility implements CampaignSpendEligibilityInterface
{
    /**
     * @param array<int, SpendFailureReason|null> $results
     */
    public function __construct(private array $results)
    {
    }

    public function rejectionReason(int $organizationId, int $campaignId, int $pointsAmount, DateTimeImmutable $at): ?SpendFailureReason
    {
        return $this->results[$campaignId] ?? null;
    }
}
