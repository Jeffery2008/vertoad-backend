<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Service\Reporting\ReportQueryService;

final class DatabaseReportAggregateRepositoryTest extends TestCase
{
    public function testRefreshCountsZeroCostTrafficWithoutAddingUnbilledMoney(): void
    {
        $connection = $this->createConnection(withAggregateTable: true);
        $this->seedTraffic($connection);
        $repository = new DatabaseReportAggregateRepository($connection);

        $metrics = $repository->refreshFromEvents(
            new DateTimeImmutable('2026-07-10T00:00:00+00:00'),
            new DateTimeImmutable('2026-07-11T00:00:00+00:00'),
        );
        $advertiser = (new ReportQueryService($repository))->dashboard([
            'portal' => 'advertiser',
            'organization_id' => 99,
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-07-10T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-07-11T00:00:00+00:00'),
            'granularity' => 'day',
        ]);
        $publisher = (new ReportQueryService($repository))->dashboard([
            'portal' => 'publisher',
            'organization_id' => 42,
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-07-10T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-07-11T00:00:00+00:00'),
            'granularity' => 'day',
        ]);

        self::assertSame(['day_rows' => 3, 'hour_rows' => 3], $metrics);
        self::assertSame(2, $advertiser['totals']['impressions']);
        self::assertSame(2, $advertiser['totals']['clicks']);
        self::assertSame(100.0, $advertiser['totals']['ctr']);
        self::assertSame(160, $advertiser['totals']['spend_points']);
        self::assertSame(0, $advertiser['totals']['revenue_points']);
        self::assertSame(2, $publisher['totals']['impressions']);
        self::assertSame(2, $publisher['totals']['clicks']);
        self::assertSame(0, $publisher['totals']['spend_points']);
        self::assertSame(96, $publisher['totals']['revenue_points']);
    }

    public function testRawFallbackUsesTheSameTrafficAndFinancialSemantics(): void
    {
        $connection = $this->createConnection(withAggregateTable: false);
        $this->seedTraffic($connection);
        $repository = new DatabaseReportAggregateRepository($connection);
        $filters = [
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-07-10T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-07-11T00:00:00+00:00'),
            'granularity' => 'day',
        ];

        $advertiser = $repository->query([
            ...$filters,
            'portal' => 'advertiser',
            'organization_id' => 99,
        ]);
        $publisher = $repository->query([
            ...$filters,
            'portal' => 'publisher',
            'organization_id' => 42,
        ]);

        self::assertCount(1, $advertiser);
        self::assertSame(2, $advertiser[0]->impressions);
        self::assertSame(2, $advertiser[0]->clicks);
        self::assertSame(160, $advertiser[0]->spendPoints);
        self::assertSame(0, $advertiser[0]->revenuePoints);
        self::assertCount(1, $publisher);
        self::assertSame(2, $publisher[0]->impressions);
        self::assertSame(2, $publisher[0]->clicks);
        self::assertSame(0, $publisher[0]->spendPoints);
        self::assertSame(96, $publisher[0]->revenuePoints);
    }

    private function createConnection(bool $withAggregateTable): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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
                billing_status VARCHAR(32) NOT NULL DEFAULT "pending",
                billed_points INTEGER NOT NULL DEFAULT 0,
                publisher_earning_points INTEGER NOT NULL DEFAULT 0,
                billing_reason VARCHAR(120) NULL,
                billing_processed_at DATETIME NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id)
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

        if ($withAggregateTable) {
            $connection->executeStatement(
                'CREATE TABLE report_aggregates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    granularity VARCHAR(12) NOT NULL,
                    bucket_start DATETIME NOT NULL,
                    dimension_key VARCHAR(64) NOT NULL,
                    organization_role VARCHAR(16) NOT NULL DEFAULT "platform",
                    organization_id INTEGER NULL,
                    campaign_id INTEGER NULL,
                    site_id INTEGER NOT NULL,
                    slot_id INTEGER NOT NULL,
                    geo VARCHAR(120) NULL,
                    device VARCHAR(120) NULL,
                    browser VARCHAR(120) NULL,
                    resolution VARCHAR(120) NULL,
                    risk_bucket VARCHAR(120) NULL,
                    impressions INTEGER NOT NULL,
                    clicks INTEGER NOT NULL,
                    spend_points INTEGER NOT NULL,
                    revenue_points INTEGER NOT NULL,
                    conversions INTEGER NOT NULL DEFAULT 0,
                    conversion_value_points INTEGER NOT NULL DEFAULT 0,
                    refreshed_at DATETIME NOT NULL,
                    UNIQUE (granularity, bucket_start, dimension_key)
                )',
            );
        }

        return $connection;
    }

    private function seedTraffic(Connection $connection): void
    {
        $this->insertEvent($connection, 'impression', 'cpm-impression', 'billed', null, 40, 24, true, '2026-07-10 10:00:00');
        $this->insertEvent($connection, 'click', 'cpm-zero-cost-click', 'skipped', 'zero_cost', 700, 500, true, '2026-07-10 10:01:00');
        $this->insertEvent($connection, 'impression', 'cpc-zero-cost-impression', 'skipped', 'zero_cost', 800, 600, true, '2026-07-10 10:02:00');
        $this->insertEvent($connection, 'click', 'cpc-click', 'billed', null, 120, 72, true, '2026-07-10 10:03:00');
        $this->insertEvent($connection, 'click', 'budget-rejected-click', 'skipped', 'insufficient_balance', 900, 700, true, '2026-07-10 10:04:00');
        $this->insertEvent($connection, 'impression', 'invalid-impression', 'skipped', 'zero_cost', 900, 700, false, '2026-07-10 10:05:00');
    }

    private function insertEvent(
        Connection $connection,
        string $eventType,
        string $eventId,
        string $billingStatus,
        ?string $billingReason,
        int $billedPoints,
        int $publisherEarningPoints,
        bool $valid,
        string $occurredAt,
    ): void {
        $connection->insert('ad_serving_events', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'decision_id' => 'decision-' . $eventId,
            'site_id' => 5,
            'slot_id' => 10,
            'viewer_id' => 'commercial-viewer',
            'ad_id' => 'asset-1',
            'campaign_id' => 30,
            'advertiser_organization_id' => 99,
            'publisher_organization_id' => 42,
            'cost_points' => $billingReason === 'zero_cost' ? 0 : $billedPoints,
            'occurred_at' => $occurredAt,
            'valid' => $valid ? 1 : 0,
            'reason' => $valid ? null : 'invalid_traffic',
            'visible_ratio' => $eventType === 'impression' ? 0.75 : null,
            'visible_ms' => $eventType === 'impression' ? 1_500 : null,
            'billing_status' => $billingStatus,
            'billed_points' => $billedPoints,
            'publisher_earning_points' => $publisherEarningPoints,
            'billing_reason' => $billingReason,
            'billing_processed_at' => $occurredAt,
            'processed_at' => $occurredAt,
        ]);
    }
}
