<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Serving\DatabaseAdCandidateRepository;
use VertoAD\Repository\Serving\DatabaseServingInventoryRepository;
use VertoAD\Repository\Serving\EmptyAdCandidateRepository;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Tests\Campaigns\CampaignSchema;

final class ServingRepositoryTest extends TestCase
{
    public function testDatabaseInventoryRequiresVerifiedSiteAndActiveSlot(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseServingInventoryRepository($connection);

        self::assertTrue($repository->isVerifiedActiveSlot(10, 20));
        self::assertSame(1, $repository->publisherOrganizationIdForSlot(10, 20));
        self::assertFalse($repository->isVerifiedActiveSlot(10, 21));
        self::assertNull($repository->publisherOrganizationIdForSlot(10, 21));
        self::assertFalse($repository->isVerifiedActiveSlot(11, 22));
        self::assertFalse($repository->isVerifiedActiveSlot(99, 20));
    }

    public function testDatabaseCandidateRepositoryReturnsApprovedActiveCampaignsInBidOrder(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture($connection, campaignId: 100, assetId: 200, bidPoints: 90, pricingModel: 'cpm');
        $this->insertCandidateFixture($connection, campaignId: 101, assetId: 201, bidPoints: 150, pricingModel: 'cpc');

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]);

        self::assertCount(2, $candidates);
        self::assertSame(101, $candidates[0]->campaignId);
        self::assertSame('asset-201', $candidates[0]->adId);
        self::assertSame(0, $candidates[0]->impressionCostPoints);
        self::assertSame(150, $candidates[0]->clickCostPoints);
        self::assertSame(100, $candidates[1]->campaignId);
        self::assertSame(90, $candidates[1]->impressionCostPoints);
        self::assertSame(0, $candidates[1]->clickCostPoints);
        self::assertStringContainsString('data-vertoad-asset="organizations/99/assets/creative-201.png"', $candidates[0]->creativeHtml);
        self::assertSame('image', $candidates[0]->assetType);
        self::assertSame('organizations/99/assets/creative-201.png', $candidates[0]->assetObjectKey);
        self::assertSame('image/png', $candidates[0]->assetContentType);
    }

    public function testDatabaseCandidateRepositoryHydratesServingQualityCtrAndCaps(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture($connection, campaignId: 100, assetId: 200, aiRiskScore: 0.25);
        $connection->insert('report_aggregates', [
            'granularity' => 'day',
            'bucket_start' => '2026-06-08 00:00:00',
            'dimension_key' => hash('sha256', 'serving-quality-ctr'),
            'organization_role' => 'advertiser',
            'organization_id' => 99,
            'campaign_id' => 100,
            'site_id' => 10,
            'slot_id' => 20,
            'geo' => null,
            'device' => null,
            'browser' => null,
            'resolution' => null,
            'risk_bucket' => null,
            'impressions' => 400,
            'clicks' => 20,
            'spend_points' => 1000,
            'revenue_points' => 500,
            'refreshed_at' => '2026-06-08 12:00:00',
        ]);
        $connection->insert('campaign_serving_frequency_caps', [
            'campaign_id' => 100,
            'organization_id' => 99,
            'hourly_impression_cap' => 2,
            'daily_impression_cap' => 5,
            'hourly_click_cap' => 3,
            'daily_click_cap' => 7,
            'updated_at' => '2026-06-08 12:00:00',
        ]);

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]);

        self::assertCount(1, $candidates);
        self::assertSame(75, $candidates[0]->qualityScore);
        self::assertSame(50, $candidates[0]->historicalCtrPerMille);
        self::assertSame(2, $candidates[0]->hourlyFrequencyCap);
        self::assertSame(5, $candidates[0]->dailyFrequencyCap);
        self::assertTrue(property_exists($candidates[0], 'hourlyClickCap'));
        self::assertTrue(property_exists($candidates[0], 'dailyClickCap'));
        self::assertSame(3, $candidates[0]->hourlyClickCap);
        self::assertSame(7, $candidates[0]->dailyClickCap);
    }

    public function testDatabaseCandidateRepositoryHydratesGeoTargetingRules(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture(
            $connection,
            campaignId: 100,
            assetId: 200,
            targeting: ['site_ids' => [10], 'slot_ids' => [20], 'geos' => ['CN-SH', 'CN-BJ']],
        );

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]);

        self::assertCount(1, $candidates);
        self::assertSame(['CN-SH', 'CN-BJ'], $candidates[0]->geos);
    }

    public function testDatabaseCandidateRepositoryDefaultsMissingQualityCtrAndCaps(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture($connection, campaignId: 100, assetId: 200, aiRiskScore: null);

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]);

        self::assertCount(1, $candidates);
        self::assertSame(100, $candidates[0]->qualityScore);
        self::assertSame(0, $candidates[0]->historicalCtrPerMille);
        self::assertNull($candidates[0]->hourlyFrequencyCap);
        self::assertNull($candidates[0]->dailyFrequencyCap);
    }

    public function testDatabaseCandidateRepositoryExcludesIneligibleCampaignCreativeAndTargetingRows(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture($connection, campaignId: 100, assetId: 200);
        $this->insertCandidateFixture($connection, campaignId: 101, assetId: 201, campaignStatus: 'paused');
        $this->insertCandidateFixture($connection, campaignId: 102, assetId: 202, assetStatus: 'pending_upload');
        $this->insertCandidateFixture($connection, campaignId: 103, assetId: 203, reviewStatus: 'rejected', finalDecision: 'rejected');
        $this->insertCandidateFixture($connection, campaignId: 104, assetId: 204, startsAt: '2027-01-01 00:00:00');
        $this->insertCandidateFixture($connection, campaignId: 105, assetId: 205, endsAt: '2025-01-01 00:00:00');
        $this->insertCandidateFixture($connection, campaignId: 106, assetId: 206, width: 728, height: 90);
        $this->insertCandidateFixture($connection, campaignId: 107, assetId: 207, targeting: ['site_ids' => [99], 'slot_ids' => []]);
        $this->insertCandidateFixture($connection, campaignId: 108, assetId: 208, targeting: ['site_ids' => [10], 'slot_ids' => [99]]);
        $this->insertCandidateFixture($connection, campaignId: 109, assetId: 209, landingUrl: '');
        $this->insertCandidateFixture($connection, campaignId: 110, assetId: 210, finalDecision: null);

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]);

        self::assertCount(1, $candidates);
        self::assertSame(100, $candidates[0]->campaignId);
    }

    public function testDatabaseCandidateRepositoryHandlesUntargetedAndInvalidTargetingPayloads(): void
    {
        $connection = $this->createCampaignConnection();
        $this->insertCandidateFixture($connection, campaignId: 100, assetId: 200, targeting: ['site_ids' => [], 'slot_ids' => []]);
        $this->insertCandidateFixture($connection, campaignId: 101, assetId: 201, targetingJson: '{bad-json');

        $candidates = (new DatabaseAdCandidateRepository($connection))
            ->eligibleCandidatesForSlot(10, 20, null);

        self::assertCount(2, $candidates);
        self::assertSame([100, 101], array_map(static fn ($candidate): int => $candidate->campaignId, $candidates));
    }

    public function testEmptyCandidateRepositoryRemainsExplicitNoFillTestDouble(): void
    {
        $repository = new EmptyAdCandidateRepository();

        self::assertSame([], $repository->eligibleCandidatesForSlot(10, 20, ['width' => 300, 'height' => 250]));
    }

    public function testInMemoryEventRepositoryCanLeaseAndAcknowledgeServingEventsForCron(): void
    {
        $repository = new InMemoryAdEventRepository();
        $repository->recordImpression(
            new AdDecision(
                decisionId: 'decision-buffer',
                siteId: 10,
                slotId: 20,
                viewerId: 'viewer-buffer',
                filled: true,
                reason: null,
                iframeHtml: '<div>Ad</div>',
                width: 300,
                height: 250,
                adId: 'ad-buffer',
                campaignId: 30,
                advertiserOrganizationId: 40,
                publisherOrganizationId: 50,
                impressionCostPoints: 60,
                clickCostPoints: 70,
                landingUrl: 'https://advertiser.example/landing',
                decidedAt: new DateTimeImmutable('2026-06-08T09:59:00Z'),
            ),
            'imp-buffer',
            0.75,
            1500,
            new DateTimeImmutable('2026-06-08T10:00:00Z'),
        );

        $leased = $repository->lease(1);
        self::assertCount(1, $leased);
        self::assertSame('imp-buffer', $leased[0]->eventId);

        $repository->acknowledge($leased[0]);

        self::assertSame([], $repository->lease(1));
        self::assertFalse($repository->hasEvent('impression', 'imp-buffer'));
    }

    public function testInMemoryEventRepositoryFailRemovesPoisonEventFromCronLease(): void
    {
        $repository = new InMemoryAdEventRepository();
        $repository->recordClick(
            new AdDecision(
                decisionId: 'decision-buffer',
                siteId: 10,
                slotId: 20,
                viewerId: 'viewer-buffer',
                filled: true,
                reason: null,
                iframeHtml: '<div>Ad</div>',
                width: 300,
                height: 250,
                adId: 'ad-buffer',
                campaignId: 30,
                advertiserOrganizationId: 40,
                publisherOrganizationId: 50,
                impressionCostPoints: 60,
                clickCostPoints: 70,
                landingUrl: 'https://advertiser.example/landing',
                decidedAt: new DateTimeImmutable('2026-06-08T09:59:00Z'),
            ),
            'clk-poison',
            new DateTimeImmutable('2026-06-08T10:00:00Z'),
        );

        $event = $repository->lease(1)[0];
        $repository->fail($event, new \RuntimeException('poison event'));

        self::assertSame([], $repository->lease(1));
        self::assertFalse($repository->hasEvent('click', 'clk-poison'));
    }

    public function testInMemoryEventRepositoryRejectsInvalidCronLeaseLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cron event consume batch size must be positive.');

        (new InMemoryAdEventRepository())->lease(0);
    }

    public function testInMemoryDecisionRepositorySearchFiltersAndLimit(): void
    {
        $repository = new InMemoryAdDecisionRepository();
        $repository->save($this->inMemoryDecision(
            decisionId: 'decision-old',
            requestId: 'req-old',
            ipAddress: '198.51.100.1',
            decidedAt: new DateTimeImmutable('2026-06-08T09:00:00Z'),
        ));
        $repository->save($this->inMemoryDecision(
            decisionId: 'decision-match',
            requestId: 'req-match',
            ipAddress: '198.51.100.2',
            decidedAt: new DateTimeImmutable('2026-06-08T10:00:00Z'),
        ));
        $repository->save($this->inMemoryDecision(
            decisionId: 'decision-new',
            requestId: 'req-new',
            ipAddress: '198.51.100.2',
            decidedAt: new DateTimeImmutable('2026-06-08T10:30:00Z'),
        ));

        self::assertSame([], $repository->searchDecisions(['request_id' => 'req-missing']));
        self::assertSame([], $repository->searchDecisions(['ip_address' => '198.51.100.3']));
        self::assertSame([], $repository->searchDecisions(['occurred_from' => '2026-06-08T11:00:00Z']));
        self::assertSame([], $repository->searchDecisions(['occurred_to' => '2026-06-08T08:00:00Z']));

        $matches = $repository->searchDecisions([
            'ip_address' => '198.51.100.2',
            'occurred_from' => '2026-06-08T09:30:00Z',
            'occurred_to' => '2026-06-08T10:15:00Z',
            'limit' => 1,
        ]);

        self::assertSame(['decision-match'], array_map(static fn (AdDecision $decision): string => $decision->decisionId, $matches));
        self::assertSame(
            ['decision-old', 'decision-match', 'decision-new'],
            array_map(static fn (AdDecision $decision): string => $decision->decisionId, $repository->searchDecisions([])),
        );
    }

    public function testInMemoryEventRepositorySearchFiltersAndLimit(): void
    {
        $repository = new InMemoryAdEventRepository();
        $oldDecision = $this->inMemoryDecision(
            decisionId: 'decision-old',
            requestId: 'req-old-decision',
            ipAddress: '198.51.100.1',
            decidedAt: new DateTimeImmutable('2026-06-08T09:00:00Z'),
        );
        $matchDecision = $this->inMemoryDecision(
            decisionId: 'decision-match',
            requestId: 'req-match-decision',
            ipAddress: '198.51.100.2',
            decidedAt: new DateTimeImmutable('2026-06-08T09:59:00Z'),
        );

        $repository->recordImpression($oldDecision, 'imp-old', 0.75, 1500, new DateTimeImmutable('2026-06-08T09:00:00Z'), 'req-old');
        $repository->recordImpression($matchDecision, 'imp-match', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00Z'), 'req-match');
        $repository->recordClick($matchDecision, 'clk-match', new DateTimeImmutable('2026-06-08T10:05:00Z'), 'req-click');

        self::assertSame([], $repository->searchEvents(['request_id' => 'req-missing']));
        self::assertSame([], $repository->searchEvents(['ip_address' => '198.51.100.3']));
        self::assertSame([], $repository->searchEvents(['event_type' => 'video']));
        self::assertSame([], $repository->searchEvents(['occurred_from' => '2026-06-08T11:00:00Z']));
        self::assertSame([], $repository->searchEvents(['occurred_to' => '2026-06-08T08:00:00Z']));

        $matches = $repository->searchEvents([
            'ip_address' => '198.51.100.2',
            'event_type' => 'impression',
            'occurred_from' => '2026-06-08T09:30:00Z',
            'occurred_to' => '2026-06-08T10:15:00Z',
            'limit' => 1,
        ]);

        self::assertSame(['imp-match'], array_map(static fn ($event): string => $event->eventId, $matches));
        self::assertSame(['imp-old', 'imp-match', 'clk-match'], array_map(static fn ($event): string => $event->eventId, $repository->searchEvents([])));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE sites (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                domain TEXT NOT NULL,
                status TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_slots (
                id INTEGER PRIMARY KEY,
                site_id INTEGER NOT NULL,
                status TEXT NOT NULL
            )',
        );
        $connection->insert('sites', ['id' => 10, 'organization_id' => 1, 'domain' => 'publisher.example', 'status' => 'verified']);
        $connection->insert('sites', ['id' => 11, 'organization_id' => 1, 'domain' => 'pending.example', 'status' => 'pending']);
        $connection->insert('ad_slots', ['id' => 20, 'site_id' => 10, 'status' => 'active']);
        $connection->insert('ad_slots', ['id' => 21, 'site_id' => 10, 'status' => 'paused']);
        $connection->insert('ad_slots', ['id' => 22, 'site_id' => 11, 'status' => 'active']);

        return $connection;
    }

    private function createCampaignConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CampaignSchema::create($connection);

        return $connection;
    }

    /**
     * @param array{site_ids?: list<int>, slot_ids?: list<int>, devices?: list<string>, geos?: list<string>, time_windows?: list<array<string, mixed>>}|null $targeting
     */
    private function insertCandidateFixture(
        Connection $connection,
        int $campaignId,
        int $assetId,
        string $campaignStatus = 'active',
        string $assetStatus = 'pending_review',
        string $reviewStatus = 'approved',
        ?string $finalDecision = 'approved',
        int $bidPoints = 100,
        string $pricingModel = 'cpm',
        int $width = 300,
        int $height = 250,
        ?string $startsAt = null,
        ?string $endsAt = null,
        string $landingUrl = 'https://advertiser.example/landing',
        ?array $targeting = ['site_ids' => [10], 'slot_ids' => [20]],
        ?string $targetingJson = null,
        ?float $aiRiskScore = 0.01,
    ): void {
        $connection->insert('asset_upload_intents', [
            'id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative-' . $assetId . '.png',
            'object_key' => 'organizations/99/assets/creative-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'confirmed',
            'expires_at' => '2026-06-08 11:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $assetId,
            'upload_intent_id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/99/assets/creative-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => $width,
            'height' => $height,
            'duration_seconds' => null,
            'checksum' => 'sha256:' . $assetId,
            'status' => $assetStatus,
        ]);
        $connection->insert('creative_reviews', [
            'asset_id' => $assetId,
            'organization_id' => 99,
            'status' => $reviewStatus,
            'ai_provider' => 'deterministic',
            'ai_model' => 'deterministic-v1',
            'ai_risk_score' => $aiRiskScore,
            'ai_risk_labels' => '[]',
            'ai_reasons' => '[]',
            'ai_raw_result' => null,
            'requested_by_user_id' => 7,
            'final_decision' => $finalDecision,
            'final_decision_reason' => null,
            'final_decided_by_user_id' => 7,
            'final_decided_at' => '2026-06-08 10:00:00',
        ]);
        $connection->insert('campaigns', [
            'id' => $campaignId,
            'organization_id' => 99,
            'name' => 'Campaign ' . $campaignId,
            'status' => $campaignStatus,
            'pricing_model' => $pricingModel,
            'bid_points' => $bidPoints,
            'landing_url' => $landingUrl,
            'creative_asset_id' => $assetId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'targeting_json' => $targetingJson ?? json_encode($targeting ?? [], JSON_THROW_ON_ERROR),
        ]);
    }

    private function inMemoryDecision(string $decisionId, string $requestId, string $ipAddress, DateTimeImmutable $decidedAt): AdDecision
    {
        return new AdDecision(
            decisionId: $decisionId,
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: '<iframe title="Advertisement"></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: $decidedAt,
            requestId: $requestId,
            ipAddress: $ipAddress,
            userAgent: 'In-memory test browser',
            geoCode: 'CN-SH',
        );
    }
}
