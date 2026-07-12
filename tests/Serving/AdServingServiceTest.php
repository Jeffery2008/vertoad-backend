<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Campaign\CampaignTimeWindow;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Repository\Cron\ServingRequestEventBufferInterface;
use VertoAD\Repository\Operations\InMemoryOperationRiskDecisionLogRepository;
use VertoAD\Repository\Operations\OperationRiskDecisionLogRepositoryInterface;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Billing\CpmBillingUnavailableException;
use VertoAD\Service\Billing\CpmChargeEstimatorInterface;
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

    public function testServeReturnsNoFillWithRequestScopedDecisionIdWhenNoEligibleAdExists(): void
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
        self::assertMatchesRegularExpression('/^no-fill:[a-f0-9]{32}$/', $decision->decisionId);
        self::assertStringContainsString('data-vertoad-no-fill="1"', $decision->iframeHtml);
    }

    public function testNoFillDecisionIdsAreUniqueAtTheSameTimeAndRetainBothRequestIds(): void
    {
        $decisions = new InMemoryAdDecisionRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([]),
            $decisions,
            new InMemoryAdEventRepository(),
        );
        $now = new DateTimeImmutable('2026-06-08T10:00:00Z');

        $first = $service->serve(
            10,
            20,
            'viewer-same-input',
            null,
            false,
            $now,
            new ServingRequestContext(requestId: 'req-no-fill-a'),
        );
        $second = $service->serve(
            10,
            20,
            'viewer-same-input',
            null,
            false,
            $now,
            new ServingRequestContext(requestId: 'req-no-fill-b'),
        );

        self::assertFalse($first->filled);
        self::assertFalse($second->filled);
        self::assertNotSame($first->decisionId, $second->decisionId);
        self::assertMatchesRegularExpression('/^no-fill:[a-f0-9]{32}$/', $first->decisionId);
        self::assertMatchesRegularExpression('/^no-fill:[a-f0-9]{32}$/', $second->decisionId);
        self::assertSame('req-no-fill-a', $decisions->find($first->decisionId)?->requestId);
        self::assertSame('req-no-fill-b', $decisions->find($second->decisionId)?->requestId);
    }

    public function testFilledDecisionIdsAreUniqueAtTheSameTimeAndRetainBothRequestIds(): void
    {
        $decisions = new InMemoryAdDecisionRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            $decisions,
            new InMemoryAdEventRepository(),
        );
        $now = new DateTimeImmutable('2026-06-08T10:00:00Z');

        $first = $service->serve(
            10,
            20,
            'viewer-same-input',
            null,
            false,
            $now,
            new ServingRequestContext(requestId: 'req-filled-a'),
        );
        $second = $service->serve(
            10,
            20,
            'viewer-same-input',
            null,
            false,
            $now,
            new ServingRequestContext(requestId: 'req-filled-b'),
        );

        self::assertTrue($first->filled);
        self::assertTrue($second->filled);
        self::assertNotSame($first->decisionId, $second->decisionId);
        self::assertMatchesRegularExpression('/^ad:[a-f0-9]{32}$/', $first->decisionId);
        self::assertMatchesRegularExpression('/^ad:[a-f0-9]{32}$/', $second->decisionId);
        self::assertSame('req-filled-a', $decisions->find($first->decisionId)?->requestId);
        self::assertSame('req-filled-b', $decisions->find($second->decisionId)?->requestId);
    }

    public function testServeRecordsRequestTelemetryWhenProductionBufferIsConfigured(): void
    {
        $serveEvents = new RecordingServingRequestEventBuffer();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            serveEvents: $serveEvents,
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-telemetry',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
            new ServingRequestContext(requestId: 'req-serve-telemetry'),
        );

        self::assertSame([$decision], $serveEvents->decisions());
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
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
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
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );

        $decision = $service->serve(10, 20, 'viewer-beijing', null, false, new DateTimeImmutable('2026-06-08 10:00:00'), 'CN-BJ');

        self::assertFalse($decision->filled);
        self::assertSame('geo_target_mismatch', $decision->reason);
    }

    public function testServeSkipsGeoTargetedCandidatesWhenGeoIsUnknownButKeepsUntargetedCandidates(): void
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

        $decision = $service->serve(10, 20, 'viewer-unknown-geo', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertTrue($decision->filled);
        self::assertSame('ad-untargeted', $decision->adId);
        self::assertNull($decision->geoCode);
    }

    public function testServeAppliesDeviceTargetingAndKeepsUntargetedFallbackForUnknownDevices(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-mobile',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/mobile',
                    impressionCostPoints: 100,
                    clickCostPoints: 0,
                    devices: ['mobile'],
                ),
                $this->candidate(
                    adId: 'ad-unrestricted',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/all-devices',
                    impressionCostPoints: 10,
                    clickCostPoints: 0,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );
        $now = new DateTimeImmutable('2026-06-08T10:00:00Z');

        $mobile = $service->serve(
            10,
            20,
            'viewer-mobile',
            null,
            false,
            $now,
            new ServingRequestContext(userAgent: 'Mozilla/5.0 (Linux; Android 14) Mobile'),
        );
        $desktop = $service->serve(
            10,
            20,
            'viewer-desktop',
            null,
            false,
            $now,
            new ServingRequestContext(userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'),
        );
        $unknown = $service->serve(
            10,
            20,
            'viewer-unknown-device',
            null,
            false,
            $now,
            new ServingRequestContext(userAgent: 'curl/8.0'),
        );

        self::assertSame('ad-mobile', $mobile->adId);
        self::assertSame('ad-unrestricted', $desktop->adId);
        self::assertSame('ad-unrestricted', $unknown->adId);
    }

    public function testServeNoFillsWhenDeviceIsUnknownAndEveryCandidateIsDeviceTargeted(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-tablet',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/tablet',
                    impressionCostPoints: 10,
                    clickCostPoints: 0,
                    devices: ['tablet'],
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-unknown-device',
            null,
            false,
            new DateTimeImmutable('2026-06-08T10:00:00Z'),
            new ServingRequestContext(),
        );

        self::assertFalse($decision->filled);
        self::assertSame('device_target_mismatch', $decision->reason);
    }

    public function testServeSelectsCandidatesInsideConfiguredLocalTimeWindows(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate(
                    adId: 'ad-closed',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    landingUrl: 'https://advertiser.example/closed',
                    impressionCostPoints: 100,
                    clickCostPoints: 0,
                    timeWindows: [new CampaignTimeWindow(2, '09:00', '18:00', 'UTC')],
                ),
                $this->candidate(
                    adId: 'ad-open',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/open',
                    impressionCostPoints: 10,
                    clickCostPoints: 0,
                    timeWindows: [new CampaignTimeWindow(1, '09:00', '18:00', 'UTC')],
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-business-hours',
            null,
            false,
            new DateTimeImmutable('2026-06-08T10:00:00Z'),
        );

        self::assertTrue($decision->filled);
        self::assertSame('ad-open', $decision->adId);
    }

    public function testServeHandlesCrossMidnightAndTimezoneBoundariesAndRejectsClosedWindows(): void
    {
        $candidate = $this->candidate(
            adId: 'ad-overnight',
            campaignId: 30,
            advertiserOrganizationId: 40,
            landingUrl: 'https://advertiser.example/overnight',
            impressionCostPoints: 10,
            clickCostPoints: 0,
            timeWindows: [new CampaignTimeWindow(1, '22:00', '02:00', 'Asia/Shanghai')],
        );
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$candidate]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );

        $mondayLocal = $service->serve(
            10,
            20,
            'viewer-overnight-start',
            null,
            false,
            new DateTimeImmutable('2026-06-08T14:30:00Z'),
        );
        $tuesdayLocal = $service->serve(
            10,
            20,
            'viewer-overnight-carry',
            null,
            false,
            new DateTimeImmutable('2026-06-08T17:30:00Z'),
        );
        $closed = $service->serve(
            10,
            20,
            'viewer-overnight-closed',
            null,
            false,
            new DateTimeImmutable('2026-06-08T18:00:00Z'),
        );

        self::assertTrue($mondayLocal->filled);
        self::assertTrue($tuesdayLocal->filled);
        self::assertFalse($closed->filled);
        self::assertSame('time_target_mismatch', $closed->reason);
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

    public function testFabricRendererUrlEnforcesPublicUrlSafetyBoundaries(): void
    {
        $candidate = $this->assetCandidate(
            adId: 'ad-fabric-renderer-boundary',
            assetType: 'fabric_snapshot',
            assetContentType: 'application/json',
            assetUrl: 'https://assets.example.test/fabric/creative.json',
            snapshotWebpUrl: 'https://assets.example.test/fabric/creative.webp',
        );

        foreach (
            [
                [' https://sdk.example.test/fabric-renderer.js ', 'https://sdk.example.test/fabric-renderer.js'],
                ['http://localhost:5173/fabric-renderer.js', 'http://localhost:5173/fabric-renderer.js'],
                ['http://127.0.0.1:5173/fabric-renderer.js', 'http://127.0.0.1:5173/fabric-renderer.js'],
            ] as [$configuredUrl, $renderedUrl]
        ) {
            $service = new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository([$candidate]),
                new InMemoryAdDecisionRepository(),
                new InMemoryAdEventRepository(),
                fabricRendererUrl: $configuredUrl,
            );

            $decision = $service->serve(10, 20, 'viewer-renderer-boundary', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
            $srcdoc = html_entity_decode($decision->iframeHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            self::assertTrue($decision->filled);
            self::assertStringContainsString('data-vertoad-fabric-renderer src="' . $renderedUrl . '"', $srcdoc);
        }

        foreach (
            [
                'http://sdk.example.test/fabric-renderer.js',
                'https://user:secret@sdk.example.test/fabric-renderer.js',
                'https://sdk.example.test/fabric-renderer.js?v=1',
                'https://sdk.example.test/fabric-renderer.js#latest',
                '/fabric-renderer.js',
                'https://[',
            ] as $rendererUrl
        ) {
            try {
                new AdServingService(
                    new StaticServingInventoryRepository(verifiedSlots: []),
                    new StaticAdCandidateRepository([]),
                    new InMemoryAdDecisionRepository(),
                    new InMemoryAdEventRepository(),
                    fabricRendererUrl: $rendererUrl,
                );
                self::fail('Unsafe Fabric renderer URL must be rejected: ' . $rendererUrl);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'Fabric renderer URL must use HTTPS, except for localhost development.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testServeRendersSupportedAssetMarkupAndRenderModes(): void
    {
        $cases = [
            [
                $this->assetCandidate(
                    adId: 'ad-image-markup',
                    assetType: 'image',
                    assetContentType: 'image/webp',
                    assetUrl: 'https://assets.example.test/image/original.webp',
                    snapshotWebpUrl: 'http://localhost:8080/image/snapshot.webp',
                ),
                'snapshot',
                [
                    'data-vertoad-asset-type="image"',
                    '<img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" src="http://localhost:8080/image/snapshot.webp">',
                ],
            ],
            [
                $this->assetCandidate(
                    adId: 'ad-text-markup',
                    assetType: 'text',
                    assetContentType: 'text/plain',
                    assetUrl: '',
                    snapshotWebpUrl: 'https://assets.example.test/text/snapshot.webp',
                ),
                'snapshot',
                [
                    'data-vertoad-asset-type="text"',
                    '<img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" src="https://assets.example.test/text/snapshot.webp">',
                ],
            ],
            [
                $this->assetCandidate(
                    adId: 'ad-video-markup',
                    assetType: 'video',
                    assetContentType: 'video/webm',
                    assetUrl: 'http://127.0.0.1:8080/video/creative.webm',
                    snapshotWebpUrl: 'https://assets.example.test/video/poster.webp',
                ),
                'video',
                [
                    'data-vertoad-asset-type="video"',
                    '<video class="vertoad-media" data-vertoad-video controls playsinline preload="metadata" poster="https://assets.example.test/video/poster.webp">',
                    '<source src="http://127.0.0.1:8080/video/creative.webm" type="video/webm">',
                    '<img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" hidden src="https://assets.example.test/video/poster.webp">',
                    'data-vertoad-video-cta',
                ],
            ],
            [
                $this->assetCandidate(
                    adId: 'ad-placeholder-markup',
                    assetType: 'html_placeholder',
                    assetContentType: '',
                    assetUrl: '',
                    snapshotWebpUrl: '',
                ),
                'empty',
                [
                    'data-vertoad-asset-type="html_placeholder"',
                    '<div data-vertoad-fallback="empty"></div>',
                ],
            ],
        ];

        foreach ($cases as [$candidate, $renderMode, $expectedMarkup]) {
            $service = new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository([$candidate]),
                new InMemoryAdDecisionRepository(),
                new InMemoryAdEventRepository(),
            );

            $decision = $service->serve(10, 20, 'viewer-' . $candidate->adId, null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
            $srcdoc = html_entity_decode($decision->iframeHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            self::assertTrue($decision->filled, $candidate->assetType . ' should be renderable.');
            self::assertStringContainsString('"render_mode":"' . $renderMode . '"', $srcdoc);
            foreach ($expectedMarkup as $markup) {
                self::assertStringContainsString($markup, $srcdoc);
            }
            if ($candidate->assetType === 'video') {
                $document = new \DOMDocument();
                self::assertTrue(@$document->loadHTML($srcdoc));
                $xpath = new \DOMXPath($document);
                $linkedVideos = $xpath->query('//a[@data-vertoad-click-target]//video[@data-vertoad-video]');
                $videos = $xpath->query('//video[@data-vertoad-video]');
                $videoCtas = $xpath->query('//a[@data-vertoad-video-cta and @data-vertoad-click-target]');
                self::assertNotFalse($linkedVideos);
                self::assertNotFalse($videos);
                self::assertNotFalse($videoCtas);
                self::assertSame(0, $linkedVideos->length);
                self::assertSame(1, $videos->length);
                self::assertSame(1, $videoCtas->length);
            }
            self::assertStringNotContainsString('<script>window.top.location', $srcdoc);
        }
    }

    public function testServeNoFillsWhenEveryCandidateHasUnsafeAssetMetadataOrUrl(): void
    {
        $unsafeCandidates = [
            $this->assetCandidate('ad-unsupported-type', 'script', 'application/javascript', 'https://assets.example.test/ad.js', 'https://assets.example.test/ad.webp'),
            $this->assetCandidate('ad-image-content-type', 'image', 'text/html', '', 'https://assets.example.test/image.webp'),
            $this->assetCandidate('ad-image-query', 'image', 'image/webp', '', 'https://assets.example.test/image.webp?signature=secret'),
            $this->assetCandidate('ad-video-content-type', 'video', 'video/quicktime', 'https://assets.example.test/video.mov', 'https://assets.example.test/video.webp'),
            $this->assetCandidate('ad-video-remote-http', 'video', 'video/mp4', 'http://assets.example.test/video.mp4', 'https://assets.example.test/video.webp'),
            $this->assetCandidate('ad-video-fragment', 'video', 'video/webm', 'https://assets.example.test/video.webm', 'https://assets.example.test/video.webp#poster'),
            $this->assetCandidate('ad-fabric-content-type', 'fabric_snapshot', 'text/html', 'https://assets.example.test/fabric.json', 'https://assets.example.test/fabric.webp'),
            $this->assetCandidate('ad-fabric-credentials', 'fabric_snapshot', 'application/json', 'https://user:secret@assets.example.test/fabric.json', 'https://assets.example.test/fabric.webp'),
            $this->assetCandidate('ad-text-content-type', 'text', 'text/html', '', 'https://assets.example.test/text.webp'),
            $this->assetCandidate('ad-text-protocol-relative', 'text', 'text/plain', '', '//assets.example.test/text.webp'),
        ];
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository($unsafeCandidates),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            fabricRendererUrl: 'https://sdk.example.test/fabric-renderer.js',
        );

        $decision = $service->serve(10, 20, 'viewer-unsafe-assets', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertFalse($decision->filled);
        self::assertSame('unsafe_asset_url', $decision->reason);
        self::assertStringContainsString('data-vertoad-no-fill="1"', $decision->iframeHtml);

        $missingRenderer = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->assetCandidate(
                    'ad-fabric-missing-renderer',
                    'fabric_snapshot',
                    'application/json',
                    'https://assets.example.test/fabric.json',
                    'https://assets.example.test/fabric.webp',
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $missingRendererDecision = $missingRenderer->serve(
            10,
            20,
            'viewer-missing-renderer',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
        );

        self::assertFalse($missingRendererDecision->filled);
        self::assertSame('unsafe_asset_url', $missingRendererDecision->reason);
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
            assetUrl: 'https://assets.example.test/organizations/40/assets/fabric-creative.json',
            snapshotPngUrl: 'https://assets.example.test/organizations/40/assets/fabric-creative.png',
            snapshotWebpUrl: 'https://assets.example.test/organizations/40/assets/fabric-creative.webp',
            thumbnailWebpUrl: 'https://assets.example.test/organizations/40/assets/fabric-creative.thumb.webp',
        );
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$candidate]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            fabricRendererUrl: 'https://sdk.example.test/fabric-renderer.js',
        );

        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $srcdoc = html_entity_decode($decision->iframeHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        self::assertTrue($decision->filled);
        self::assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"', $decision->iframeHtml);
        self::assertStringContainsString('/api/v1/ads/click?decision_id=', $srcdoc);
        self::assertStringNotContainsString('https://advertiser.example/landing', $srcdoc);
        self::assertStringContainsString('data-vertoad-renderer="platform-controlled"', $srcdoc);
        self::assertStringContainsString('"render_mode":"fabric-json"', $srcdoc);
        self::assertStringContainsString('"asset_url":"https:\/\/assets.example.test\/organizations\/40\/assets\/fabric-creative.json"', $srcdoc);
        self::assertStringContainsString('"fallback_url":"https:\/\/assets.example.test\/organizations\/40\/assets\/fabric-creative.webp"', $srcdoc);
        self::assertStringContainsString('data-vertoad-fabric-renderer src="https://sdk.example.test/fabric-renderer.js"', $srcdoc);
        self::assertStringNotContainsString('asset_object_key', $srcdoc);
        self::assertStringNotContainsString('fallback_object_key', $srcdoc);
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
        $riskAssessor = new MutableServingRiskAssessor(AdTrafficRiskDecision::allow());
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            $decisions,
            $events,
            null,
            new DefaultAdSelectionPolicy(riskAssessor: $riskAssessor),
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $noFill = $service->serve(10, 21, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $riskAssessor->setDecision(AdTrafficRiskDecision::reject('fraud_click_risk'));

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
        self::assertSame(1, $riskAssessor->assessmentCount());
    }

    public function testClickRequiresPriorValidImpression(): void
    {
        $events = new InMemoryAdEventRepository();
        $riskAssessor = new MutableServingRiskAssessor(AdTrafficRiskDecision::allow());
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            null,
            new DefaultAdSelectionPolicy(riskAssessor: $riskAssessor),
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $riskAssessor->setDecision(AdTrafficRiskDecision::reject('fraud_click_risk'));

        $click = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:05'));
        self::assertFalse($click->accepted);
        self::assertSame('valid_impression_required', $click->reason);
        self::assertSame(0, $events->clickCount());
        self::assertSame(1, $riskAssessor->assessmentCount());
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

    public function testClickRechecksLatestTrafficRiskAndReplaysInvalidOutcomeWithoutFrequencyGrowth(): void
    {
        $riskAssessor = new MutableServingRiskAssessor(AdTrafficRiskDecision::allow());
        $frequencyCaps = new InMemoryServingFrequencyCapStore();
        $events = new InMemoryAdEventRepository();
        $riskLogs = new InMemoryOperationRiskDecisionLogRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            null,
            new DefaultAdSelectionPolicy(
                frequencyCaps: $frequencyCaps,
                riskAssessor: $riskAssessor,
            ),
            null,
            $riskLogs,
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $decision = $service->serve(
            10,
            20,
            'viewer-click-risk',
            null,
            false,
            $now,
            new ServingRequestContext(
                ipAddress: '198.51.100.10',
                userAgent: 'Serve Browser',
                geoCode: 'CN-SH',
                requestId: 'req-serve-click-risk',
            ),
        );
        $impression = $service->trackImpression(
            $decision->decisionId,
            'viewer-click-risk',
            0.75,
            1500,
            'imp-click-risk',
            $now->modify('+2 seconds'),
            'req-track-click-risk',
        );
        $riskAssessor->setDecision(AdTrafficRiskDecision::reject('fraud_click_velocity'));

        $clickAt = $now->modify('+40 seconds');
        $rejected = $service->recordClick(
            $decision->decisionId,
            'viewer-click-risk',
            'clk-high-risk',
            $clickAt,
            context: new ServingRequestContext(
                ipAddress: '203.0.113.73',
                userAgent: 'Click Risk Browser',
                geoCode: 'JP-13',
                requestId: 'req-click-risk',
                endpoint: '/api/v1/ads/click',
                httpMethod: 'get',
            ),
        );

        self::assertTrue($decision->filled);
        self::assertTrue($impression->accepted);
        self::assertFalse($rejected->accepted);
        self::assertSame('fraud_click_velocity', $rejected->reason);
        self::assertNull($rejected->redirectUrl);
        self::assertSame([
            ['site_id' => 10, 'slot_id' => 20, 'viewer_id' => 'viewer-click-risk'],
            ['site_id' => 10, 'slot_id' => 20, 'viewer_id' => 'viewer-click-risk'],
        ], $riskAssessor->assessments());

        $invalidClick = $events->findEvent('click', 'clk-high-risk');
        self::assertNotNull($invalidClick);
        self::assertFalse($invalidClick->valid);
        self::assertSame('fraud_click_velocity', $invalidClick->reason);
        self::assertSame('req-click-risk', $invalidClick->requestId);
        self::assertSame('203.0.113.73', $invalidClick->ipAddress);
        self::assertSame('Click Risk Browser', $invalidClick->userAgent);
        self::assertSame('JP-13', $invalidClick->geoCode);
        self::assertSame(0, $events->clickCount());
        self::assertSame(0, $frequencyCaps->clickCount(30, 20, 'viewer-click-risk', 'hour', $clickAt));

        $logs = $riskLogs->search(['request_id' => 'req-click-risk', 'action' => 'ads.click.invalid']);
        self::assertCount(1, $logs);
        self::assertSame(['fraud_click_velocity'], $logs[0]->reason_codes);
        self::assertSame('click', $logs[0]->subject_type);
        self::assertSame('clk-high-risk', $logs[0]->subject_id);
        self::assertSame('req-click-risk', $logs[0]->request_id);
        self::assertSame('203.0.113.73', $logs[0]->ip_address);
        self::assertSame('Click Risk Browser', $logs[0]->user_agent);
        self::assertSame('/api/v1/ads/click', $logs[0]->endpoint);
        self::assertSame('GET', $logs[0]->http_method);
        self::assertSame(10, $logs[0]->site_id);
        self::assertSame(20, $logs[0]->slot_id);
        self::assertSame(30, $logs[0]->campaign_id);
        self::assertSame('viewer-click-risk', $logs[0]->viewer_id);
        self::assertSame($decision->decisionId, $logs[0]->ad_decision_id);

        $riskAssessor->setDecision(AdTrafficRiskDecision::allow());
        $replay = $service->recordClick(
            $decision->decisionId,
            'viewer-click-risk',
            'clk-high-risk',
            $clickAt->modify('+1 minute'),
            'req-click-risk-replay',
            new ServingRequestContext(
                ipAddress: '192.0.2.44',
                userAgent: 'Replay Browser',
                geoCode: 'US-CA',
                requestId: 'req-click-risk-replay',
            ),
        );

        self::assertFalse($replay->accepted);
        self::assertSame('fraud_click_velocity', $replay->reason);
        self::assertNull($replay->redirectUrl);
        self::assertCount(2, $riskAssessor->assessments());
        self::assertCount(2, $events->events());
        self::assertCount(1, $riskLogs->search(['action' => 'ads.click.invalid']));
        self::assertSame(0, $frequencyCaps->clickCount(30, 20, 'viewer-click-risk', 'hour', $clickAt));
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

    public function testRepeatClickWritesDurableRiskDecisionLog(): void
    {
        $events = new InMemoryAdEventRepository();
        $riskLogs = new InMemoryOperationRiskDecisionLogRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            null,
            null,
            null,
            $riskLogs,
        );
        $decision = $service->serve(
            10,
            20,
            'viewer-1',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
            new ServingRequestContext(
                ipAddress: '198.51.100.44',
                userAgent: 'Repeat Browser',
                geoCode: 'US-CA',
                requestId: 'req-serve-repeat',
            ),
        );
        $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'imp-1', new DateTimeImmutable('2026-06-08 10:00:02'), 'req-track-repeat');
        $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:35'), 'req-click-accepted');

        $repeat = $service->recordClick(
            $decision->decisionId,
            'viewer-1',
            'clk-2',
            new DateTimeImmutable('2026-06-08 10:00:45'),
            'req-click-repeat',
            new ServingRequestContext(
                ipAddress: '203.0.113.99',
                userAgent: 'Click Browser',
                geoCode: 'JP-13',
                requestId: 'req-click-repeat',
                endpoint: '/api/v1/ads/click',
                httpMethod: 'GET',
            ),
        );
        $logs = $riskLogs->search(['request_id' => 'req-click-repeat', 'action' => 'ads.click.invalid']);

        self::assertFalse($repeat->accepted);
        self::assertSame('repeat_click_window', $repeat->reason);
        self::assertCount(1, $logs);
        self::assertSame('click', $logs[0]->subject_type);
        self::assertSame('clk-2', $logs[0]->subject_id);
        self::assertSame($decision->decisionId, $logs[0]->ad_decision_id);
        self::assertSame(['repeat_click_window'], $logs[0]->reason_codes);
        self::assertSame('203.0.113.99', $logs[0]->ip_address);
        self::assertSame('Click Browser', $logs[0]->user_agent);
    }

    public function testRepeatClickStillReturnsRejectionWhenRiskDecisionLogWriteFails(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
            null,
            null,
            null,
            new ThrowingRiskDecisionLogRepository(),
        );
        $decision = $service->serve(10, 20, 'viewer-1', null, false, new DateTimeImmutable('2026-06-08 10:00:00'));
        $service->trackImpression($decision->decisionId, 'viewer-1', 0.5, 1000, 'imp-1', new DateTimeImmutable('2026-06-08 10:00:02'));
        $service->recordClick($decision->decisionId, 'viewer-1', 'clk-1', new DateTimeImmutable('2026-06-08 10:00:35'));

        $repeat = $service->recordClick($decision->decisionId, 'viewer-1', 'clk-2', new DateTimeImmutable('2026-06-08 10:00:45'), 'req-click-repeat');

        self::assertFalse($repeat->accepted);
        self::assertSame('repeat_click_window', $repeat->reason);
        self::assertSame('repeat_click_window', $events->findEvent('click', 'clk-2')?->reason);
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

    public function testServeFailsClosedForMissingAndInvalidCpmRevenueShareRules(): void
    {
        foreach (['missing_revenue_share_rule', 'zero_publisher_earning'] as $reason) {
            $service = new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository([
                    $this->candidate('ad-cpm-unavailable', 30, 40, 'https://advertiser.example/cpm', 999, 0),
                ]),
                new InMemoryAdDecisionRepository(),
                new InMemoryAdEventRepository(),
                cpmChargeEstimator: new UnavailableCpmChargeEstimator($reason),
            );

            $decision = $service->serve(
                10,
                20,
                'viewer-cpm-' . $reason,
                null,
                false,
                new DateTimeImmutable('2026-06-08 10:00:00'),
            );

            self::assertFalse($decision->filled);
            self::assertSame($reason, $decision->reason);
        }
    }

    public function testServeFailsClosedWhenCpmEstimatorIsNotConfigured(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate('ad-cpm-no-estimator', 30, 40, 'https://advertiser.example/cpm', 999, 0),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-cpm-no-estimator',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
        );

        self::assertFalse($decision->filled);
        self::assertSame('cpm_charge_estimator_unavailable', $decision->reason);
    }

    public function testServeSkipsUnavailableCpmCandidateAndUsesFundedCpcCandidate(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                $this->candidate('ad-cpm-unavailable', 30, 40, 'https://advertiser.example/cpm', 999, 0),
                $this->candidate('ad-cpc-funded', 31, 41, 'https://advertiser.example/cpc', 0, 10),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            cpmChargeEstimator: new UnavailableCpmChargeEstimator('missing_revenue_share_rule'),
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-cpm-fallback',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
        );

        self::assertTrue($decision->filled);
        self::assertSame('ad-cpc-funded', $decision->adId);
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
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
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
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
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

    public function testAcceptedClickCountsTowardClickFrequencyCapsAndNextServeSkipsCappedCandidate(): void
    {
        self::assertTrue(property_exists(AdCandidate::class, 'hourlyClickCap'), 'Ad candidates must expose hourly click caps.');
        self::assertTrue(method_exists(InMemoryServingFrequencyCapStore::class, 'clickCount'), 'Frequency cap stores must expose click counts.');
        self::assertTrue(method_exists(InMemoryServingFrequencyCapStore::class, 'recordClick'), 'Frequency cap stores must record accepted clicks.');

        $frequencyCaps = new InMemoryServingFrequencyCapStore();
        $policy = new DefaultAdSelectionPolicy($frequencyCaps);
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                new AdCandidate(
                    adId: 'ad-click-capped',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    creativeHtml: '<strong>VertoAD creative</strong>',
                    landingUrl: 'https://advertiser.example/capped',
                    width: 300,
                    height: 250,
                    impressionCostPoints: 100,
                    clickCostPoints: 0,
                    hourlyClickCap: 1,
                ),
                $this->candidate(
                    adId: 'ad-available-after-click-cap',
                    campaignId: 31,
                    advertiserOrganizationId: 41,
                    landingUrl: 'https://advertiser.example/available',
                    impressionCostPoints: 20,
                    clickCostPoints: 0,
                ),
            ]),
            $decisions,
            $events,
            null,
            $policy,
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $first = $service->serve(10, 20, 'viewer-click-cap', null, false, $now);
        $service->trackImpression($first->decisionId, 'viewer-click-cap', 0.75, 1500, 'imp-click-cap-1', $now->modify('+2 seconds'));
        $click = $service->recordClick($first->decisionId, 'viewer-click-cap', 'clk-click-cap-1', $now->modify('+40 seconds'));
        $second = $service->serve(10, 20, 'viewer-click-cap', null, false, $now->modify('+5 minutes'));

        self::assertSame('ad-click-capped', $first->adId);
        self::assertTrue($click->accepted);
        self::assertSame(1, $frequencyCaps->clickCount(30, 20, 'viewer-click-cap', 'hour', $now));
        self::assertSame('ad-available-after-click-cap', $second->adId);
    }

    public function testRejectedRepeatClickDoesNotIncrementClickFrequencyCaps(): void
    {
        self::assertTrue(property_exists(AdCandidate::class, 'hourlyClickCap'), 'Ad candidates must expose hourly click caps.');
        self::assertTrue(method_exists(InMemoryServingFrequencyCapStore::class, 'clickCount'), 'Frequency cap stores must expose click counts.');

        $frequencyCaps = new InMemoryServingFrequencyCapStore();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([
                new AdCandidate(
                    adId: 'ad-click-capped',
                    campaignId: 30,
                    advertiserOrganizationId: 40,
                    creativeHtml: '<strong>VertoAD creative</strong>',
                    landingUrl: 'https://advertiser.example/capped',
                    width: 300,
                    height: 250,
                    impressionCostPoints: 100,
                    clickCostPoints: 0,
                    hourlyClickCap: 2,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            new DefaultAdSelectionPolicy($frequencyCaps),
            cpmChargeEstimator: new FixedCpmChargeEstimator(0),
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $decision = $service->serve(10, 20, 'viewer-repeat-click-cap', null, false, $now);
        $service->trackImpression($decision->decisionId, 'viewer-repeat-click-cap', 0.75, 1500, 'imp-repeat-click-cap', $now->modify('+2 seconds'));
        $accepted = $service->recordClick($decision->decisionId, 'viewer-repeat-click-cap', 'clk-repeat-click-cap-1', $now->modify('+40 seconds'));
        $repeat = $service->recordClick($decision->decisionId, 'viewer-repeat-click-cap', 'clk-repeat-click-cap-2', $now->modify('+45 seconds'));

        self::assertTrue($accepted->accepted);
        self::assertFalse($repeat->accepted);
        self::assertSame('repeat_click_window', $repeat->reason);
        self::assertSame(1, $frequencyCaps->clickCount(30, 20, 'viewer-repeat-click-cap', 'hour', $now));
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

    public function testTracksVideoPlaybackTelemetryWithoutViewabilityOrBillingCost(): void
    {
        $events = new InMemoryAdEventRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            $events,
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $decision = $service->serve(10, 20, 'viewer-video', null, false, $now);
        $accepted = $service->trackVideoEvent($decision->decisionId, 'viewer-video', 'video_50', 'video-progress-1', $now->modify('+10 seconds'), 'req-video-1');
        $duplicate = $service->trackVideoEvent($decision->decisionId, 'viewer-video', 'video_50', 'video-progress-1', $now->modify('+11 seconds'), 'req-video-duplicate');
        $unknown = $service->trackVideoEvent($decision->decisionId, 'viewer-video', 'video_replay', 'video-replay-1', $now->modify('+12 seconds'), 'req-video-invalid');

        $event = $events->findEvent('video_50', 'video-progress-1');
        self::assertTrue($accepted->accepted);
        self::assertFalse($accepted->duplicate);
        self::assertTrue($duplicate->accepted);
        self::assertTrue($duplicate->duplicate);
        self::assertFalse($unknown->accepted);
        self::assertSame('invalid_event_type', $unknown->reason);
        self::assertSame(0, $events->impressionCount());
        self::assertNotNull($event);
        self::assertSame(0, $event->costPoints);
        self::assertNull($event->visibleRatio);
        self::assertNull($event->visibleMs);
        self::assertSame('req-video-1', $event->requestId);
    }

    public function testVideoTelemetryRejectsMissingNoFillAndWrongViewerDecisions(): void
    {
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $decision = $service->serve(10, 20, 'viewer-video', null, false, $now);
        $noFill = $service->serve(10, 21, 'viewer-video', null, false, $now);

        $missing = $service->trackVideoEvent('missing', 'viewer-video', 'video_start', 'video-missing', $now->modify('+10 seconds'));
        $noFillResult = $service->trackVideoEvent($noFill->decisionId, 'viewer-video', 'video_start', 'video-no-fill', $now->modify('+11 seconds'));
        $wrongViewer = $service->trackVideoEvent($decision->decisionId, 'viewer-other', 'video_start', 'video-wrong-viewer', $now->modify('+12 seconds'));

        self::assertFalse($missing->accepted);
        self::assertSame('decision_not_found', $missing->reason);
        self::assertFalse($noFillResult->accepted);
        self::assertSame('decision_not_found', $noFillResult->reason);
        self::assertFalse($wrongViewer->accepted);
        self::assertSame('decision_not_found', $wrongViewer->reason);
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
        $riskLogs = new InMemoryOperationRiskDecisionLogRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            new DefaultAdSelectionPolicy(
                riskAssessor: new FixedServingRiskAssessor(new AdTrafficRiskDecision(false, 'fraud_high_risk_viewer')),
            ),
            null,
            $riskLogs,
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-risk',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
            new ServingRequestContext(
                ipAddress: '198.51.100.9',
                userAgent: 'Risk Browser',
                geoCode: 'CN-SH',
                requestId: 'req-risk-serve',
            ),
        );
        $logs = $riskLogs->search(['request_id' => 'req-risk-serve', 'action' => 'ads.serve.risk_rejected']);

        self::assertFalse($decision->filled);
        self::assertSame('fraud_high_risk_viewer', $decision->reason);
        self::assertCount(1, $logs);
        self::assertSame('req-risk-serve', $logs[0]->request_id);
        self::assertSame($decision->decisionId, $logs[0]->ad_decision_id);
        self::assertSame(['fraud_high_risk_viewer'], $logs[0]->reason_codes);
        self::assertSame('198.51.100.9', $logs[0]->ip_address);
        self::assertSame('Risk Browser', $logs[0]->user_agent);
    }

    public function testServeStillReturnsNoFillWhenRiskDecisionLogWriteFails(): void
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
            null,
            new ThrowingRiskDecisionLogRepository(),
        );

        $decision = $service->serve(
            10,
            20,
            'viewer-risk',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
            new ServingRequestContext(
                ipAddress: '198.51.100.9',
                userAgent: 'Risk Browser',
                geoCode: 'CN-SH',
                requestId: 'req-risk-serve',
            ),
        );

        self::assertFalse($decision->filled);
        self::assertSame('fraud_high_risk_viewer', $decision->reason);
    }

    public function testRiskDecisionLogSkipsMissingRequestIdAndDefaultsEmptyReasonCodes(): void
    {
        $riskLogs = new InMemoryOperationRiskDecisionLogRepository();
        $service = new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            new DefaultAdSelectionPolicy(
                riskAssessor: new FixedServingRiskAssessor(new AdTrafficRiskDecision(false, 'fraud_high_risk_viewer')),
            ),
            null,
            $riskLogs,
        );
        $rejected = $service->serve(
            10,
            20,
            'viewer-missing-request',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:00:00'),
            new ServingRequestContext(ipAddress: '198.51.100.9'),
        );

        self::assertFalse($rejected->filled);
        self::assertSame([], $riskLogs->search(['action' => 'ads.serve.risk_rejected']));

        $decision = (new AdServingService(
            new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
            new StaticAdCandidateRepository([$this->safeCandidate()]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
            null,
            DefaultAdSelectionPolicy::inMemory(),
            null,
            $riskLogs,
        ))->serve(
            10,
            20,
            'viewer-empty-reason',
            null,
            false,
            new DateTimeImmutable('2026-06-08 10:01:00'),
            new ServingRequestContext(requestId: 'req-empty-reason'),
        );

        $method = new \ReflectionMethod(AdServingService::class, 'appendRiskDecisionLog');
        $method->invokeArgs($service, [
            $decision,
            'ads.click.invalid',
            [],
            'click',
            'clk-empty-reason',
            new DateTimeImmutable('2026-06-08 10:01:05'),
            'req-empty-reason',
            '/api/v1/ads/click',
            null,
        ]);
        $logs = $riskLogs->search(['request_id' => 'req-empty-reason']);

        self::assertCount(1, $logs);
        self::assertSame(['invalid_traffic'], $logs[0]->reason_codes);
        self::assertNull($logs[0]->http_method);
    }

    private function safeCandidate(): AdCandidate
    {
        return $this->candidate('ad-1', 30, 40, 'https://advertiser.example/landing', 10, 20);
    }

    private function assetCandidate(
        string $adId,
        string $assetType,
        string $assetContentType,
        string $assetUrl,
        string $snapshotWebpUrl,
    ): AdCandidate {
        return new AdCandidate(
            adId: $adId,
            campaignId: 30,
            advertiserOrganizationId: 40,
            creativeHtml: '<script>window.top.location="https://evil.example"</script>',
            landingUrl: 'https://advertiser.example/landing',
            width: 300,
            height: 250,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            assetType: $assetType,
            assetObjectKey: 'organizations/40/assets/' . $adId,
            assetContentType: $assetContentType,
            assetUrl: $assetUrl,
            snapshotPngUrl: 'https://assets.example.test/snapshots/' . $adId . '.png',
            snapshotWebpUrl: $snapshotWebpUrl,
            thumbnailWebpUrl: 'https://assets.example.test/thumbnails/' . $adId . '.webp',
        );
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
        array $devices = [],
        array $timeWindows = [],
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
            devices: $devices,
            timeWindows: $timeWindows,
        );
    }
}

final readonly class FixedCpmChargeEstimator implements CpmChargeEstimatorInterface
{
    public function __construct(private int $points)
    {
    }

    public function nextChargePoints(
        int $advertiserOrganizationId,
        int $campaignId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $bidPointsPerThousand,
    ): int {
        return $this->points;
    }
}

final readonly class UnavailableCpmChargeEstimator implements CpmChargeEstimatorInterface
{
    public function __construct(private string $reason)
    {
    }

    public function nextChargePoints(
        int $advertiserOrganizationId,
        int $campaignId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $bidPointsPerThousand,
    ): int {
        throw new CpmBillingUnavailableException($this->reason);
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

final class RecordingServingRequestEventBuffer implements ServingRequestEventBufferInterface
{
    /** @var list<AdDecision> */
    private array $decisions = [];

    public function recordServe(AdDecision $decision): void
    {
        $this->decisions[] = $decision;
    }

    /** @return list<AdDecision> */
    public function decisions(): array
    {
        return $this->decisions;
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

final class MutableServingRiskAssessor implements ServingRiskAssessorInterface
{
    /** @var list<array{site_id:int,slot_id:int,viewer_id:string}> */
    private array $assessments = [];

    public function __construct(private AdTrafficRiskDecision $decision)
    {
    }

    public function setDecision(AdTrafficRiskDecision $decision): void
    {
        $this->decision = $decision;
    }

    public function assess(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision
    {
        $this->assessments[] = [
            'site_id' => $siteId,
            'slot_id' => $slotId,
            'viewer_id' => $viewerId,
        ];

        return $this->decision;
    }

    /** @return list<array{site_id:int,slot_id:int,viewer_id:string}> */
    public function assessments(): array
    {
        return $this->assessments;
    }

    public function assessmentCount(): int
    {
        return count($this->assessments);
    }
}

final class ThrowingRiskDecisionLogRepository implements OperationRiskDecisionLogRepositoryInterface
{
    public function append(\VertoAD\Domain\Operations\OperationRiskDecisionLog $entry): \VertoAD\Domain\Operations\OperationRiskDecisionLog
    {
        throw new \RuntimeException('risk decision log table unavailable');
    }

    public function find(string $decisionId): ?\VertoAD\Domain\Operations\OperationRiskDecisionLog
    {
        return null;
    }

    public function search(array $filters = []): array
    {
        return [];
    }
}
