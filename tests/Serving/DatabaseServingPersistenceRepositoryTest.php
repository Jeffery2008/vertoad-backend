<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Billing\AdEventBillingResult;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;

final class DatabaseServingPersistenceRepositoryTest extends TestCase
{
    public function testDecisionsSurviveRepositoryInstances(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();

        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $stored = (new DatabaseAdDecisionRepository($connection))->find($decision->decisionId);

        self::assertNotNull($stored);
        self::assertSame($decision->decisionId, $stored->decisionId);
        self::assertSame($decision->siteId, $stored->siteId);
        self::assertSame($decision->slotId, $stored->slotId);
        self::assertSame($decision->viewerId, $stored->viewerId);
        self::assertTrue($stored->filled);
        self::assertNull($stored->reason);
        self::assertSame($decision->iframeHtml, $stored->iframeHtml);
        self::assertSame($decision->width, $stored->width);
        self::assertSame($decision->height, $stored->height);
        self::assertSame($decision->adId, $stored->adId);
        self::assertSame($decision->campaignId, $stored->campaignId);
        self::assertSame($decision->advertiserOrganizationId, $stored->advertiserOrganizationId);
        self::assertSame($decision->publisherOrganizationId, $stored->publisherOrganizationId);
        self::assertSame($decision->impressionCostPoints, $stored->impressionCostPoints);
        self::assertSame($decision->clickCostPoints, $stored->clickCostPoints);
        self::assertSame($decision->landingUrl, $stored->landingUrl);
        self::assertSame($decision->decidedAt->getTimestamp(), $stored->decidedAt->getTimestamp());
        self::assertSame($decision->requestId, $stored->requestId);
        self::assertSame($decision->ipAddress, $stored->ipAddress);
        self::assertSame($decision->userAgent, $stored->userAgent);
        self::assertSame($decision->geoCode, $stored->geoCode);
    }

    public function testUpdatingDecisionDoesNotDeleteExistingEvents(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        $decisions = new DatabaseAdDecisionRepository($connection);
        $decisions->save($decision);
        (new DatabaseAdEventRepository($connection))->recordClick(
            $decision,
            'clk-before-update',
            new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        );

        $decisions->save($decision->withLandingUrl('https://advertiser.example/updated'));

        $stored = $decisions->find($decision->decisionId);
        self::assertNotNull($stored);
        self::assertSame('https://advertiser.example/updated', $stored->landingUrl);
        self::assertTrue((new DatabaseAdEventRepository($connection))->hasEvent('click', 'clk-before-update'));
    }

    public function testEventsSurviveInstancesAndSupportServingGuards(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-1', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordImpression($decision, 'imp-1', 0.8, 2000, new DateTimeImmutable('2026-06-08T10:00:01+00:00'));
        $events->recordClick($decision, 'clk-1', new DateTimeImmutable('2026-06-08T10:00:20+00:00'));
        $events->recordInvalidClick($decision, 'clk-repeat', new DateTimeImmutable('2026-06-08T10:00:25+00:00'), 'repeat_click_window');

        $fresh = new DatabaseAdEventRepository($connection);

        self::assertTrue($fresh->hasEvent('impression', 'imp-1'));
        self::assertCount(3, $fresh->lease(10));
        self::assertTrue($fresh->hasValidImpression($decision->decisionId, $decision->viewerId));
        self::assertTrue($fresh->hasRecentValidClick($decision->decisionId, $decision->viewerId, new DateTimeImmutable('2026-06-08T10:00:30+00:00'), 30));

        $invalid = $fresh->findEvent('click', 'clk-repeat');
        self::assertNotNull($invalid);
        self::assertFalse($invalid->valid);
        self::assertSame('repeat_click_window', $invalid->reason);
        self::assertSame($decision->clickCostPoints, $invalid->costPoints);
    }

    public function testServingDecisionsAndEventsPersistRequestCorrelationFields(): void
    {
        $connection = $this->createConnection();
        $decision = new AdDecision(
            decisionId: 'ad:decision-correlated',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-correlated',
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
            decidedAt: new DateTimeImmutable('2026-06-08T09:59:00+00:00'),
            requestId: 'req-serve-db',
            ipAddress: '198.51.100.8',
            userAgent: 'DB correlation browser',
            geoCode: 'CN-SH',
        );

        $decisions = new DatabaseAdDecisionRepository($connection);
        $decisions->save($decision);
        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-correlated', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'), 'req-track-db');

        $storedDecision = $decisions->find($decision->decisionId);
        $storedEvent = $events->findEvent('impression', 'imp-correlated');
        $decisionMatches = $decisions->searchDecisions(['request_id' => 'req-serve-db']);
        $servingMatches = $events->searchEvents(['request_id' => 'req-track-db']);
        $rawPayload = json_decode((string) $connection->fetchOne("SELECT payload_json FROM raw_events WHERE event_uuid = 'impression:imp-correlated'"), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('req-serve-db', $storedDecision?->requestId);
        self::assertSame('198.51.100.8', $storedDecision?->ipAddress);
        self::assertSame('DB correlation browser', $storedDecision?->userAgent);
        self::assertSame('CN-SH', $storedDecision?->geoCode);
        self::assertCount(1, $decisionMatches);
        self::assertSame('ad:decision-correlated', $decisionMatches[0]->decisionId);
        self::assertSame('req-track-db', $storedEvent?->requestId);
        self::assertSame('198.51.100.8', $storedEvent?->ipAddress);
        self::assertSame('CN-SH', $storedEvent?->geoCode);
        self::assertCount(1, $servingMatches);
        self::assertSame('imp-correlated', $servingMatches[0]->eventId);
        self::assertSame('req-track-db', $connection->fetchOne("SELECT request_id FROM ad_serving_events WHERE event_id = 'imp-correlated'"));
        self::assertSame('req-track-db', $connection->fetchOne("SELECT request_id FROM raw_events WHERE event_uuid = 'impression:imp-correlated'"));
        self::assertSame('req-track-db', $rawPayload['request_id']);
        self::assertSame('198.51.100.8', $rawPayload['ip_address']);
        self::assertSame('CN-SH', $rawPayload['geo_code']);
    }

    public function testSearchDecisionsSupportsIpAddressTimeWindowAndLimitFilters(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseAdDecisionRepository($connection);

        $repository->save($this->decisionWithCorrelation(
            'ad:decision-search-old',
            'req-old',
            '198.51.100.8',
            new DateTimeImmutable('2026-06-08T09:00:00+00:00'),
        ));
        $repository->save($this->decisionWithCorrelation(
            'ad:decision-search-match',
            'req-match',
            '198.51.100.9',
            new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        ));
        $repository->save($this->decisionWithCorrelation(
            'ad:decision-search-new',
            'req-new',
            '198.51.100.9',
            new DateTimeImmutable('2026-06-08T10:30:00+00:00'),
        ));

        $matches = $repository->searchDecisions([
            'ip_address' => ' 198.51.100.9 ',
            'occurred_from' => '2026-06-08T09:30:00+00:00',
            'occurred_to' => '2026-06-08T10:15:00+00:00',
            'limit' => 1,
        ]);

        self::assertCount(1, $matches);
        self::assertSame('ad:decision-search-match', $matches[0]->decisionId);
    }

    public function testSearchEventsSupportsIpAddressTypeTimeWindowAndLimitFilters(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decisionWithCorrelation(
            'ad:decision-event-search',
            'req-event-decision',
            '198.51.100.10',
            new DateTimeImmutable('2026-06-08T09:59:00+00:00'),
        );
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        $repository = new DatabaseAdEventRepository($connection);
        $repository->recordImpression($decision, 'imp-search-db', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'), 'req-imp-db');
        $repository->recordClick($decision, 'clk-search-db', new DateTimeImmutable('2026-06-08T10:05:00+00:00'), 'req-click-db');
        $repository->recordClick($decision, 'clk-late-db', new DateTimeImmutable('2026-06-08T10:30:00+00:00'), 'req-late-db');

        $matches = $repository->searchEvents([
            'ip_address' => ' 198.51.100.10 ',
            'event_type' => ' click ',
            'occurred_from' => '2026-06-08T10:01:00+00:00',
            'occurred_to' => '2026-06-08T10:10:00+00:00',
            'limit' => 1,
        ]);

        self::assertCount(1, $matches);
        self::assertSame('clk-search-db', $matches[0]->eventId);
    }

    public function testPendingDuplicateLookupDistinguishesProcessedAndMissingEvents(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordClick($decision, 'clk-pending', new DateTimeImmutable('2026-06-08T10:00:20+00:00'));

        $pending = $events->findPendingDuplicate($this->adEvent('click', 'clk-pending', new DateTimeImmutable('2026-06-08T10:00:20+00:00')));
        self::assertNotNull($pending);
        self::assertSame('clk-pending', $pending->eventId);
        self::assertSame('click', $pending->eventType);

        $events->acknowledge($pending);
        self::assertNull($events->findPendingDuplicate($this->adEvent('click', 'clk-pending', new DateTimeImmutable('2026-06-08T10:00:20+00:00'))));
        self::assertNull($events->findPendingDuplicate($this->adEvent('click', 'clk-missing', new DateTimeImmutable('2026-06-08T10:00:20+00:00'))));
    }

    public function testServingEventsAreMirroredToRawEventsForPermanentArchive(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-archive', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordImpression($decision, 'imp-archive', 0.80, 2000, new DateTimeImmutable('2026-06-08T10:00:01+00:00'));

        $rawRows = $connection->fetchAllAssociative('SELECT * FROM raw_events ORDER BY event_uuid');

        self::assertCount(1, $rawRows);
        self::assertSame('impression:imp-archive', $rawRows[0]['event_uuid']);
        self::assertSame('impression', $rawRows[0]['event_type']);
        self::assertSame(40, (int) $rawRows[0]['organization_id']);
        self::assertSame(10, (int) $rawRows[0]['site_id']);
        self::assertSame(20, (int) $rawRows[0]['ad_slot_id']);
        self::assertSame(30, (int) $rawRows[0]['campaign_id']);
        self::assertNull($rawRows[0]['processed_at']);

        $payload = json_decode((string) $rawRows[0]['payload_json'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('imp-archive', $payload['event_id']);
        self::assertSame('ad:decision-1', $payload['decision_id']);
        self::assertSame('viewer-1', $payload['viewer_id']);
        self::assertSame(40, $payload['advertiser_organization_id']);
        self::assertSame(50, $payload['publisher_organization_id']);
        self::assertSame(10, $payload['cost_points']);
        self::assertTrue($payload['valid']);
        self::assertSame(0.75, $payload['visible_ratio']);
        self::assertSame(1500, $payload['visible_ms']);
    }

    public function testDedupTablesKeepServingAndRawEventIdentityGlobalAcrossOccurredAtPartitions(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-cross-partition', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordImpression($decision, 'imp-cross-partition', 0.80, 2000, new DateTimeImmutable('2026-07-08T10:00:00+00:00'));

        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-cross-partition'",
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM raw_events WHERE event_uuid = 'impression:imp-cross-partition'",
        ));
        self::assertSame('2026-06-08 10:00:00', (string) $connection->fetchOne(
            "SELECT occurred_at FROM ad_serving_event_dedup WHERE event_type = 'impression' AND event_id = 'imp-cross-partition'",
        ));
        self::assertSame('2026-06-08 10:00:00', (string) $connection->fetchOne(
            "SELECT occurred_at FROM raw_event_dedup WHERE event_uuid = 'impression:imp-cross-partition'",
        ));
    }

    public function testPersistReturnsFalseForDuplicateEventsAcrossOccurredAtPartitions(): void
    {
        $connection = $this->createConnection();
        $event = $this->adEvent('click', 'clk-cross-partition', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $duplicate = $this->adEvent('click', 'clk-cross-partition', new DateTimeImmutable('2026-07-08T10:00:00+00:00'));
        $events = new DatabaseAdEventRepository($connection);

        self::assertTrue($events->persist($event));
        self::assertFalse($events->persist($duplicate));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-cross-partition'",
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM raw_events WHERE event_uuid = 'click:clk-cross-partition'",
        ));
    }

    public function testRawEventArchiveIdentityIncludesEventTypeWhenServingEventIdsOverlap(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'shared-event-id', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordClick($decision, 'shared-event-id', new DateTimeImmutable('2026-06-08T10:00:20+00:00'));

        $rawRows = $connection->fetchAllAssociative('SELECT event_uuid, event_type, payload_json FROM raw_events ORDER BY event_uuid');

        self::assertSame(['click:shared-event-id', 'impression:shared-event-id'], array_column($rawRows, 'event_uuid'));
        self::assertSame(['click', 'impression'], array_column($rawRows, 'event_type'));

        $payloads = array_map(
            static fn (array $row): array => json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
            $rawRows,
        );
        self::assertSame(['shared-event-id', 'shared-event-id'], array_column($payloads, 'event_id'));
        self::assertSame(['click', 'impression'], array_column($payloads, 'event_type'));
    }

    public function testCronLeaseAcknowledgeMarksEventsProcessedWithoutRemovingReportHistory(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-1', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordClick($decision, 'clk-1', new DateTimeImmutable('2026-06-08T10:00:20+00:00'));

        $leased = $events->lease(10);
        self::assertSame(['imp-1', 'clk-1'], array_map(static fn ($event): string => $event->eventId, $leased));

        $events->acknowledge($leased[0]);
        $events->recordBillingResult($leased[0], AdEventBillingResult::billed(10, 6), new DateTimeImmutable('2026-06-08T10:01:00+00:00'));
        $events->recordBillingResult($leased[1], AdEventBillingResult::billed(20, 12), new DateTimeImmutable('2026-06-08T10:21:00+00:00'));

        self::assertSame(['clk-1'], array_map(static fn ($event): string => $event->eventId, $events->lease(10)));
        self::assertTrue($events->hasEvent('impression', 'imp-1'));

        $rows = (new DatabaseReportAggregateRepository($connection))->query([
            'organization_id' => 40,
            'campaign_id' => 30,
            'site_id' => 10,
            'slot_id' => 20,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(1, $rows[0]->clicks);
        self::assertSame(30, $rows[0]->spendPoints);
        self::assertSame(0, $rows[0]->revenuePoints);
        self::assertSame(0, $rows[0]->conversions);
        self::assertSame(0, $rows[0]->conversionValuePoints);
    }

    public function testCronResultUpdatesAreScopedToOccurredAtPartitionKey(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        $events = new DatabaseAdEventRepository($connection);
        $event = $this->adEvent('click', 'clk-repair-import', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $importedSameId = $this->adEvent('click', 'clk-repair-import', new DateTimeImmutable('2026-07-08T10:00:00+00:00'));
        $events->persist($event);

        $connection->insert('ad_serving_events', [
            'event_type' => $importedSameId->eventType,
            'event_id' => $importedSameId->eventId,
            'decision_id' => $importedSameId->decisionId,
            'site_id' => $importedSameId->siteId,
            'slot_id' => $importedSameId->slotId,
            'viewer_id' => $importedSameId->viewerId,
            'ad_id' => $importedSameId->adId,
            'campaign_id' => $importedSameId->campaignId,
            'advertiser_organization_id' => $importedSameId->advertiserOrganizationId,
            'publisher_organization_id' => $importedSameId->publisherOrganizationId,
            'cost_points' => $importedSameId->costPoints,
            'occurred_at' => '2026-07-08 10:00:00',
            'valid' => 1,
            'reason' => null,
            'visible_ratio' => null,
            'visible_ms' => null,
            'billing_status' => 'pending',
            'billed_points' => 0,
            'publisher_earning_points' => 0,
            'billing_reason' => null,
            'billing_processed_at' => null,
            'processed_at' => null,
        ]);

        $events->recordBillingResult($event, AdEventBillingResult::billed(20, 12), new DateTimeImmutable('2026-06-08T10:01:00+00:00'));
        $events->acknowledge($event);
        $events->recordFailure($importedSameId, new \RuntimeException('repair import failed'));

        $rows = $connection->fetchAllAssociative(
            "SELECT occurred_at, billing_status, billed_points, processed_at, billing_reason
             FROM ad_serving_events
             WHERE event_type = 'click' AND event_id = 'clk-repair-import'
             ORDER BY occurred_at",
        );

        self::assertCount(2, $rows);
        self::assertSame('2026-06-08 10:00:00', $rows[0]['occurred_at']);
        self::assertSame('billed', $rows[0]['billing_status']);
        self::assertSame(20, (int) $rows[0]['billed_points']);
        self::assertNotNull($rows[0]['processed_at']);
        self::assertSame('2026-07-08 10:00:00', $rows[1]['occurred_at']);
        self::assertSame('failed', $rows[1]['billing_status']);
        self::assertSame(0, (int) $rows[1]['billed_points']);
        self::assertNull($rows[1]['processed_at']);
        self::assertSame('repair import failed', $rows[1]['billing_reason']);
    }

    public function testCronFailMarksDatabaseEventProcessedWithoutRemovingReportHistory(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        $events = new DatabaseAdEventRepository($connection);
        $events->recordClick($decision, 'clk-poison', new DateTimeImmutable('2026-06-08T10:00:20+00:00'));

        $leased = $events->lease(10);
        self::assertSame(['clk-poison'], array_map(static fn ($event): string => $event->eventId, $leased));

        $events->fail($leased[0], new \RuntimeException('poison event'));

        self::assertSame([], $events->lease(10));
        self::assertTrue($events->hasEvent('click', 'clk-poison'));
    }

    public function testReportAggregateSupportsPublisherFilterAndHourGranularity(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision();
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        $events = new DatabaseAdEventRepository($connection);
        $events->recordImpression($decision, 'imp-1', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:05:00+00:00'));
        $events->recordClick($decision, 'clk-1', new DateTimeImmutable('2026-06-08T11:05:00+00:00'));
        $events->recordInvalidClick($decision, 'clk-invalid', new DateTimeImmutable('2026-06-08T11:10:00+00:00'), 'repeat_click_window');
        $events->recordBillingResult(
            $events->findEvent('impression', 'imp-1') ?? throw new \RuntimeException('missing impression'),
            AdEventBillingResult::billed(10, 6),
            new DateTimeImmutable('2026-06-08T10:06:00+00:00'),
        );
        $events->recordBillingResult(
            $events->findEvent('click', 'clk-1') ?? throw new \RuntimeException('missing click'),
            AdEventBillingResult::billed(20, 12),
            new DateTimeImmutable('2026-06-08T11:06:00+00:00'),
        );
        $events->recordBillingResult(
            $events->findEvent('click', 'clk-invalid') ?? throw new \RuntimeException('missing invalid click'),
            AdEventBillingResult::skipped('repeat_click_window'),
            new DateTimeImmutable('2026-06-08T11:11:00+00:00'),
        );

        $rows = (new DatabaseReportAggregateRepository($connection))->query([
            'portal' => 'publisher',
            'organization_id' => 50,
            'granularity' => 'hour',
        ]);

        self::assertCount(2, $rows);
        self::assertSame('2026-06-08T10:00:00+00:00', $rows[0]->date);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(0, $rows[0]->clicks);
        self::assertSame(0, $rows[0]->spendPoints);
        self::assertSame(6, $rows[0]->revenuePoints);
        self::assertSame(0, $rows[0]->conversions);
        self::assertSame(0, $rows[0]->conversionValuePoints);
        self::assertSame('2026-06-08T11:00:00+00:00', $rows[1]->date);
        self::assertSame(0, $rows[1]->impressions);
        self::assertSame(1, $rows[1]->clicks);
        self::assertSame(0, $rows[1]->spendPoints);
        self::assertSame(12, $rows[1]->revenuePoints);
        self::assertSame(0, $rows[1]->conversions);
        self::assertSame(0, $rows[1]->conversionValuePoints);
    }

    public function testRejectsInvalidCronLeaseLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cron event consume batch size must be positive.');

        (new DatabaseAdEventRepository($this->createConnection()))->lease(0);
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createSchema($connection);

        return $connection;
    }

    public static function createSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE ad_serving_decisions (
                decision_id VARCHAR(160) PRIMARY KEY,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                viewer_id VARCHAR(160) NOT NULL,
                filled INTEGER NOT NULL,
                reason VARCHAR(120) NULL,
                iframe_html TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                ad_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                advertiser_organization_id INTEGER NULL,
                publisher_organization_id INTEGER NULL,
                impression_cost_points INTEGER NULL,
                click_cost_points INTEGER NULL,
                landing_url TEXT NULL,
                decided_at DATETIME NOT NULL,
                request_id VARCHAR(160) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(512) NULL,
                geo_code VARCHAR(64) NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_serving_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(32) NOT NULL,
                event_id VARCHAR(160) NOT NULL,
                decision_id VARCHAR(160) NOT NULL,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                viewer_id VARCHAR(160) NOT NULL,
                ad_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                advertiser_organization_id INTEGER NULL,
                publisher_organization_id INTEGER NULL,
                cost_points INTEGER NULL,
                occurred_at DATETIME NOT NULL,
                valid INTEGER NOT NULL,
                reason VARCHAR(120) NULL,
                visible_ratio NUMERIC NULL,
                visible_ms INTEGER NULL,
                request_id VARCHAR(160) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(512) NULL,
                geo_code VARCHAR(64) NULL,
                billing_status VARCHAR(32) NOT NULL DEFAULT "pending",
                billed_points INTEGER NOT NULL DEFAULT 0,
                publisher_earning_points INTEGER NOT NULL DEFAULT 0,
                billing_reason VARCHAR(120) NULL,
                billing_processed_at DATETIME NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id, occurred_at)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_serving_event_dedup (
                event_type VARCHAR(32) NOT NULL,
                event_id VARCHAR(160) NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (event_type, event_id)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE raw_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_uuid VARCHAR(255) NOT NULL,
                organization_id INTEGER NULL,
                site_id INTEGER NULL,
                ad_slot_id INTEGER NULL,
                campaign_id INTEGER NULL,
                creative_id INTEGER NULL,
                event_type VARCHAR(64) NOT NULL,
                occurred_at DATETIME NOT NULL,
                received_at DATETIME NOT NULL,
                request_id VARCHAR(160) NULL,
                request_ip BLOB NULL,
                user_agent VARCHAR(512) NULL,
                payload_json TEXT NOT NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_uuid, occurred_at)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE raw_event_dedup (
                event_uuid VARCHAR(255) NOT NULL PRIMARY KEY,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE attribution_conversions (
                event_id VARCHAR(160) PRIMARY KEY,
                conversion_id VARCHAR(160) NOT NULL,
                organization_id INTEGER NULL,
                oauth_client_id INTEGER NULL,
                recorded_by_user_id INTEGER NULL,
                attributed INTEGER NOT NULL,
                click_event_id VARCHAR(160) NULL,
                decision_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                window_seconds INTEGER NOT NULL,
                source VARCHAR(64) NOT NULL,
                conversion_name VARCHAR(160) NOT NULL,
                value_points INTEGER NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
    }

    private function decision(): AdDecision
    {
        return new AdDecision(
            decisionId: 'ad:decision-1',
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
            decidedAt: new DateTimeImmutable('2026-06-08T09:59:00+00:00'),
            requestId: 'req-decision-1',
            ipAddress: '198.51.100.8',
            userAgent: 'DB test browser',
            geoCode: 'CN-SH',
        );
    }

    private function decisionWithCorrelation(string $decisionId, string $requestId, string $ipAddress, DateTimeImmutable $decidedAt): AdDecision
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
            userAgent: 'DB test browser',
            geoCode: 'CN-SH',
        );
    }

    private function adEvent(string $type, string $id, DateTimeImmutable $occurredAt): \VertoAD\Domain\Serving\AdEvent
    {
        return new \VertoAD\Domain\Serving\AdEvent(
            eventType: $type,
            eventId: $id,
            decisionId: 'ad:decision-1',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            costPoints: $type === 'impression' ? 10 : 20,
            occurredAt: $occurredAt,
            valid: true,
            reason: null,
            requestId: 'req-event-1',
            ipAddress: '198.51.100.8',
            userAgent: 'DB test browser',
            geoCode: 'CN-SH',
        );
    }
}
