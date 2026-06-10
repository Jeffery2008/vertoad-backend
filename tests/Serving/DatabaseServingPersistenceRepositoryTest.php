<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
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
        self::assertSame(10, $rows[0]->spendPoints);
        self::assertSame(20, $rows[0]->revenuePoints);
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

        $rows = (new DatabaseReportAggregateRepository($connection))->query([
            'organization_id' => 50,
            'granularity' => 'hour',
        ]);

        self::assertCount(2, $rows);
        self::assertSame('2026-06-08T10:00:00+00:00', $rows[0]->date);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(0, $rows[0]->clicks);
        self::assertSame('2026-06-08T11:00:00+00:00', $rows[1]->date);
        self::assertSame(0, $rows[1]->impressions);
        self::assertSame(1, $rows[1]->clicks);
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
                decided_at DATETIME NOT NULL
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
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id)
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
        );
    }
}
