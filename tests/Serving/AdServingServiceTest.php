<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\AdTrafficRiskDecision;
use VertoAD\Service\Serving\DefaultAdSelectionPolicy;
use VertoAD\Service\Serving\InMemoryServingFrequencyCapStore;
use VertoAD\Service\Serving\ServingRiskAssessorInterface;

final class AdServingServiceTest extends TestCase
{
    public function testServingEventPolicyRejectsInvalidConstructorValues(): void
    {
        foreach (
            [
                [1.1, 1000, 30, 'Minimum visible ratio must be between 0 and 1.'],
                [0.5, 0, 30, 'Minimum visible milliseconds must be at least 1.'],
                [0.5, 1000, 0, 'Repeat click window must be at least 1 second.'],
            ] as [$minVisibleRatio, $minVisibleMs, $repeatClickWindowSeconds, $message]
        ) {
            try {
                new ServingEventPolicy($minVisibleRatio, $minVisibleMs, $repeatClickWindowSeconds);
                self::fail('Invalid serving event policy constructor values must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

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

    public function testServeAppliesGeoTargetingAfterRequestIpResolution(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-shanghai',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/shanghai',
                    impressionCostPoints: 10,
                    clickCostPoints: 20,
                    geos: ['CN-SH'],
                ),
                $this->candidate(
                    adId: 'ad-untargeted',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/untargeted',
                    impressionCostPoints: 8,
                    clickCostPoints: 16,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $matched = $service->serve(10, 20, 'viewer-shanghai', null, false, new DateTimeImmutable('2026-06-08 10:00:00'), 'CN-SH');
        $mismatched = $service->serve(10, 20, 'viewer-beijing', null, false, new DateTimeImmutable('2026-06-08 10:00:00'), 'CN-BJ');

        self::assertTrue($matched->filled);
        self::assertSame('ad-shanghai', $matched->adId);
        self::assertTrue($mismatched->filled);
        self::assertSame('ad-untargeted', $mismatched->adId);
    }

    public function testServeNoFillsWhenEveryCandidateIsGeoMismatched(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-shanghai',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/shanghai',
                    impressionCostPoints: 10,
                    clickCostPoints: 20,
                    geos: ['CN-SH'],
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $decision = $service->serve(10, 20, 'viewer-beijing', null, false, new DateTimeImmutable('2026-06-08 10:00:00'), 'CN-BJ');

        self::assertFalse($decision->filled);
        self::assertSame('geo_target_mismatch', $decision->reason);
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

    public function testTrackImpressionUsesConfiguredViewabilityThreshold(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            eventPolicy: new ServingEventPolicy(
                minVisibleRatio: 0.75,
                minVisibleMs: 1500,
                repeatClickWindowSeconds: 30,
            ),
        );
        $decision = $service->serve(10, 20, 'viewer-policy', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        $oldDefaultThreshold = $service->trackImpression(
            $decision->decisionId,
            'viewer-policy',
            0.5,
            1000,
            'imp-policy-low',
            new DateTimeImmutable('2026-06-08 10:00:02'),
        );
        $configuredThreshold = $service->trackImpression(
            $decision->decisionId,
            'viewer-policy',
            0.75,
            1500,
            'imp-policy-ok',
            new DateTimeImmutable('2026-06-08 10:00:03'),
        );

        self::assertFalse($oldDefaultThreshold->accepted);
        self::assertSame('viewability_threshold_not_met', $oldDefaultThreshold->reason);
        self::assertTrue($configuredThreshold->accepted);
        self::assertSame(1, $events->impressionCount());
    }

    public function testServeUsesPlatformControlledRendererForFabricCandidates(): void
    {
        $candidate = new AdCandidate(
            adId: 'ad-fabric',
            campaignId: 30,
            advertiserOrganizationId: 40,
            creativeHtml: '<script>window.top.location="https://evil.example"</script>',
            landingUrl: 'https://advertiser.example/landing',
            width: 300,
            height: 250,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            assetType: 'fabric_snapshot',
            assetObjectKey: 'organizations/40/assets/fabric-creative.json',
            assetContentType: 'application/json',
        );
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$candidate]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $srcdoc = html_entity_decode($decision->iframeHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        self::assertTrue($decision->filled);
        self::assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"', $decision->iframeHtml);
        self::assertStringContainsString('/api/v1/ads/click?decision_id=', $srcdoc);
        self::assertStringNotContainsString('https://advertiser.example/landing', $srcdoc);
        self::assertStringContainsString('data-vertoad-renderer="platform-controlled"', $srcdoc);
        self::assertStringContainsString('"render_mode":"fabric-json"', $srcdoc);
        self::assertStringContainsString('"fallback_object_key":"organizations\/40\/assets\/fabric-creative.json"', $srcdoc);
        self::assertStringNotContainsString('<script>window.top.location', $srcdoc);
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

    public function testRepeatClickWindowUsesConfiguredPolicy(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            eventPolicy: new ServingEventPolicy(
                minVisibleRatio: 0.5,
                minVisibleMs: 1000,
                repeatClickWindowSeconds: 5,
            ),
        );
        $decision = $service->serve(10, 20, 'viewer-click-policy', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $service->trackImpression($decision->decisionId, 'viewer-click-policy', 0.5, 1000, 'imp-click-policy', new DateTimeImmutable('2026-06-08 10:00:02'));
        $firstClick = $service->recordClick($decision->decisionId, 'viewer-click-policy', 'clk-policy-1', new DateTimeImmutable('2026-06-08 10:00:35'));
        $outsideConfiguredWindow = $service->recordClick($decision->decisionId, 'viewer-click-policy', 'clk-policy-2', new DateTimeImmutable('2026-06-08 10:00:41'));

        self::assertTrue($firstClick->accepted);
        self::assertTrue($outsideConfiguredWindow->accepted);
        self::assertSame(2, $events->clickCount());
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

    public function testServeRanksCandidatesByQualityWeightedBidAndHistoricalCtr(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-high-bid-low-quality',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/high-bid',
                    impressionCostPoints: 120,
                    clickCostPoints: 0,
                    qualityScore: 20,
                    historicalCtrPerMille: 20,
                ),
                $this->candidate(
                    adId: 'ad-quality-winner',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/quality',
                    impressionCostPoints: 70,
                    clickCostPoints: 0,
                    qualityScore: 100,
                    historicalCtrPerMille: 400,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            DefaultAdSelectionPolicy::inMemory(),
        );

        $decision = $service->serve(10, 20, 'viewer-quality', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertTrue($decision->filled);
        self::assertSame('ad-quality-winner', $decision->adId);
        self::assertSame(31, $decision->campaignId);
    }

    public function testSelectionPolicyUsesDeterministicTieBreakersAfterWeightedScoreTie(): void
    {
        $policy = DefaultAdSelectionPolicy::inMemory();
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $qualityTie = $policy->rankCandidates([
            $this->candidate('ad-low-quality', 30, 40, 'https://advertiser.example/low-quality', 100, 0, qualityScore: 10),
            $this->candidate('ad-high-quality', 31, 41, 'https://advertiser.example/high-quality', 10, 0, qualityScore: 100),
        ], 10, 20, 'viewer-tie', $now);
        self::assertSame('ad-high-quality', $qualityTie[0]->adId);

        $ctrTie = $policy->rankCandidates([
            $this->candidate('ad-lower-ctr', 30, 40, 'https://advertiser.example/lower-ctr', 11, 0, qualityScore: 100),
            $this->candidate('ad-higher-ctr', 31, 41, 'https://advertiser.example/higher-ctr', 10, 0, qualityScore: 100, historicalCtrPerMille: 100),
        ], 10, 20, 'viewer-tie', $now);
        self::assertSame('ad-higher-ctr', $ctrTie[0]->adId);

        $bidTie = $policy->rankCandidates([
            $this->candidate('ad-lower-bid', 30, 40, 'https://advertiser.example/lower-bid', 10, 0, qualityScore: 0),
            $this->candidate('ad-higher-bid', 31, 41, 'https://advertiser.example/higher-bid', 20, 0, qualityScore: 0),
        ], 10, 20, 'viewer-tie', $now);
        self::assertSame('ad-higher-bid', $bidTie[0]->adId);
    }

    public function testServeSkipsFrequencyCappedCandidatesAndRecordsChosenServe(): void
    {
        $frequencyCaps = new InMemoryServingFrequencyCapStore();
        $policy = new DefaultAdSelectionPolicy($frequencyCaps);
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-capped',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/capped',
                    impressionCostPoints: 100,
                    clickCostPoints: 0,
                    hourlyFrequencyCap: 1,
                ),
                $this->candidate(
                    adId: 'ad-available',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/available',
                    impressionCostPoints: 40,
                    clickCostPoints: 0,
                    hourlyFrequencyCap: 2,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            $policy,
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $first = $service->serve(10, 20, 'viewer-frequency', null, false, $now);
        $second = $service->serve(10, 20, 'viewer-frequency', null, false, $now->modify('+5 minutes'));
        $third = $service->serve(10, 20, 'viewer-frequency', null, false, $now->modify('+10 minutes'));
        $fourth = $service->serve(10, 20, 'viewer-frequency', null, false, $now->modify('+15 minutes'));

        self::assertSame('ad-capped', $first->adId);
        self::assertSame('ad-available', $second->adId);
        self::assertSame('ad-available', $third->adId);
        self::assertFalse($fourth->filled);
        self::assertSame('frequency_cap_exceeded', $fourth->reason);
        self::assertSame(1, $frequencyCaps->servedCount(30, 20, 'viewer-frequency', 'hour', $now));
        self::assertSame(2, $frequencyCaps->servedCount(31, 20, 'viewer-frequency', 'hour', $now));
    }

    public function testServeTrackAndClickPreserveRequestIdsForCorrelation(): void
    {
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            $decisions,
            $events,
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $decision = $service->serve(10, 20, 'viewer-request-id', null, false, $now, new \VertoAD\Domain\Serving\ServingRequestContext(
            ipAddress: '198.51.100.10',
            userAgent: 'Correlation browser',
            geoCode: 'CN-SH',
            requestId: 'req-serve-1',
        ));
        $impression = $service->trackImpression($decision->decisionId, 'viewer-request-id', 0.75, 1500, 'imp-request-id', $now->modify('+2 seconds'), 'req-track-1');
        $click = $service->recordClick($decision->decisionId, 'viewer-request-id', 'clk-request-id', $now->modify('+40 seconds'), 'req-click-1');

        self::assertTrue($decision->filled);
        self::assertSame('req-serve-1', $decisions->find($decision->decisionId)?->requestId);
        self::assertSame('198.51.100.10', $decisions->find($decision->decisionId)?->ipAddress);
        self::assertSame('Correlation browser', $decisions->find($decision->decisionId)?->userAgent);
        self::assertSame('CN-SH', $decisions->find($decision->decisionId)?->geoCode);
        self::assertTrue($impression->accepted);
        self::assertSame('req-track-1', $events->findEvent('impression', 'imp-request-id')?->requestId);
        self::assertTrue($click->accepted);
        self::assertSame('req-click-1', $events->findEvent('click', 'clk-request-id')?->requestId);
    }

    public function testInMemoryFrequencyCapStoreRejectsUnsupportedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serving frequency cap window is not supported.');

        (new InMemoryServingFrequencyCapStore())
            ->servedCount(30, 20, 'viewer-frequency', 'week', new DateTimeImmutable('2026-06-08 10:00:00'));
    }

    public function testServeBlocksHighRiskTrafficBeforeCandidateSelection(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            new DefaultAdSelectionPolicy(
                riskAssessor: new FixedServingRiskAssessor(new AdTrafficRiskDecision(false, 'fraud_high_risk_viewer')),
            ),
        );

        $decision = $service->serve(10, 20, 'viewer-risk', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertFalse($decision->filled);
        self::assertSame('fraud_high_risk_viewer', $decision->reason);
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
        int $qualityScore = 100,
        int $historicalCtrPerMille = 0,
        ?int $hourlyFrequencyCap = null,
        ?int $dailyFrequencyCap = null,
        array $geos = [],
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
            qualityScore: $qualityScore,
            historicalCtrPerMille: $historicalCtrPerMille,
            hourlyFrequencyCap: $hourlyFrequencyCap,
            dailyFrequencyCap: $dailyFrequencyCap,
            geos: $geos,
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

final readonly class FixedServingRiskAssessor implements ServingRiskAssessorInterface
{
    public function __construct(private AdTrafficRiskDecision $decision)
    {
    }

    public function assess(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision
    {
        return $this->decision;
    }
}
