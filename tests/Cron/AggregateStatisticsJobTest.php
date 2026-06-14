<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Service\Cron\AggregateStatisticsJob;

final class AggregateStatisticsJobTest extends TestCase
{
    public function testRefreshesDailyAndHourlyReportAggregatesIdempotently(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-1', '2026-06-08 10:05:00', 10, 6);
        $this->insertEvent($connection, 'click', 'clk-1', '2026-06-08 10:15:00', 20, 12);
        $this->insertEvent($connection, 'click', 'clk-invalid', '2026-06-08 10:20:00', 20, 12, valid: false);
        $this->insertEvent($connection, 'click', 'clk-skipped', '2026-06-08 10:25:00', 20, 0, billingStatus: 'skipped');

        $job = new AggregateStatisticsJob(
            new DatabaseReportAggregateRepository($connection),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );

        $first = $job->run();
        $second = $job->run();

        self::assertSame('aggregate-statistics', $first->jobName);
        self::assertSame('completed', $first->status);
        self::assertSame(['day_rows' => 3, 'hour_rows' => 3], $first->metrics);
        self::assertSame(['day_rows' => 3, 'hour_rows' => 3], $second->metrics);

        $repository = new DatabaseReportAggregateRepository($connection);
        $daily = $repository->query([
            'organization_id' => 40,
            'campaign_id' => 30,
            'site_id' => 10,
            'slot_id' => 20,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);
        $hourly = $repository->query([
            'portal' => 'publisher',
            'organization_id' => 50,
            'granularity' => 'hour',
        ]);
        $platform = $repository->query([
            'portal' => 'admin',
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertCount(1, $daily);
        self::assertSame('2026-06-08', $daily[0]->date);
        self::assertSame(1, $daily[0]->impressions);
        self::assertSame(1, $daily[0]->clicks);
        self::assertSame(30, $daily[0]->spendPoints);
        self::assertSame(0, $daily[0]->revenuePoints);

        self::assertCount(1, $hourly);
        self::assertSame('2026-06-08T10:00:00+00:00', $hourly[0]->date);
        self::assertSame(1, $hourly[0]->impressions);
        self::assertSame(1, $hourly[0]->clicks);
        self::assertSame(0, $hourly[0]->spendPoints);
        self::assertSame(18, $hourly[0]->revenuePoints);

        self::assertCount(1, $platform);
        self::assertNull($platform[0]->organizationId);
        self::assertSame(30, $platform[0]->spendPoints);
        self::assertSame(18, $platform[0]->revenuePoints);
    }

    public function testRefreshIncludesAttributedConversionsByOccurredAtWithoutBillingThem(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-attribution', '2026-06-08 10:05:00', 10, 6);
        $this->insertEvent($connection, 'click', 'clk-attribution', '2026-06-08 10:15:00', 20, 12);
        $this->insertConversion(
            $connection,
            eventId: 'server_api:40:order-1',
            conversionId: 'conversion_order_1',
            clickEventId: 'clk-attribution',
            occurredAt: '2026-06-08 10:45:00',
            createdAt: '2026-06-09 00:05:00',
            valuePoints: 1_200,
        );
        $this->insertConversion(
            $connection,
            eventId: 'server_api:40:order-unattributed',
            conversionId: 'conversion_unattributed',
            clickEventId: null,
            occurredAt: '2026-06-08 10:50:00',
            createdAt: '2026-06-08 10:50:00',
            valuePoints: 9_999,
            attributed: false,
        );

        $repository = new DatabaseReportAggregateRepository($connection);
        $metrics = $repository->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );

        self::assertSame(['day_rows' => 3, 'hour_rows' => 3], $metrics);

        $advertiser = $repository->query([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);
        $publisher = $repository->query([
            'portal' => 'publisher',
            'organization_id' => 50,
            'campaign_id' => 30,
        ]);
        $nextDay = $repository->query([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'from' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-10T00:00:00+00:00'),
        ]);

        self::assertCount(1, $advertiser);
        self::assertSame(1, $advertiser[0]->conversions);
        self::assertSame(1_200, $advertiser[0]->conversionValuePoints);
        self::assertSame(30, $advertiser[0]->spendPoints);
        self::assertSame(0, $advertiser[0]->revenuePoints);

        self::assertCount(1, $publisher);
        self::assertSame(1, $publisher[0]->conversions);
        self::assertSame(1_200, $publisher[0]->conversionValuePoints);
        self::assertSame(0, $publisher[0]->spendPoints);
        self::assertSame(18, $publisher[0]->revenuePoints);
        self::assertSame([], $nextDay);
    }

    public function testRejectsInvalidAggregationWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Aggregate statistics end time must be after start time.');

        new AggregateStatisticsJob(
            new DatabaseReportAggregateRepository($this->createConnection()),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
        );
    }

    public function testRepositoryRejectsInvalidRefreshWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Report aggregate refresh end time must be after start time.');

        (new DatabaseReportAggregateRepository($this->createConnection()))->refreshFromEvents(
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
        );
    }

    public function testRepositoryRollsBackWhenAggregateTableIsMissing(): void
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
                processed_at DATETIME NULL
            )',
        );

        $this->expectException(TableNotFoundException::class);

        (new DatabaseReportAggregateRepository($connection))->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );
    }

    public function testRawEventFallbackIncludesAttributedConversionsWhenAggregateTableIsMissing(): void
    {
        $connection = $this->createRawReportingConnection();
        $this->insertEvent($connection, 'impression', 'imp-raw-attribution', '2026-06-08 10:05:00', 10, 6);
        $this->insertEvent($connection, 'click', 'clk-raw-attribution', '2026-06-08 10:15:00', 20, 12);
        $this->insertConversion(
            $connection,
            eventId: 'server_api:40:raw-order-1',
            conversionId: 'conversion_raw_order_1',
            clickEventId: 'clk-raw-attribution',
            occurredAt: '2026-06-08 11:45:00',
            createdAt: '2026-06-09 00:05:00',
            valuePoints: 1_500,
        );

        $rows = (new DatabaseReportAggregateRepository($connection))->query([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertCount(1, $rows);
        self::assertSame('2026-06-08', $rows[0]->date);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(1, $rows[0]->clicks);
        self::assertSame(30, $rows[0]->spendPoints);
        self::assertSame(0, $rows[0]->revenuePoints);
        self::assertSame(1, $rows[0]->conversions);
        self::assertSame(1_500, $rows[0]->conversionValuePoints);

        $adminOrganizationRows = (new DatabaseReportAggregateRepository($connection))->query([
            'portal' => 'admin',
            'organization_id' => 40,
            'campaign_id' => 30,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertCount(1, $adminOrganizationRows);
        self::assertSame(40, $adminOrganizationRows[0]->organizationId);
        self::assertSame(1, $adminOrganizationRows[0]->conversions);
        self::assertSame(1_500, $adminOrganizationRows[0]->conversionValuePoints);
    }

    public function testNonAlignedRefreshWindowRebuildsWholeBucketsIdempotently(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-start-hour', '2026-06-08 10:05:00', 10, 6);
        $this->insertEvent($connection, 'click', 'clk-end-hour', '2026-06-08 11:50:00', 20, 12);

        $repository = new DatabaseReportAggregateRepository($connection);
        $repository->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T10:30:00+00:00'),
            new DateTimeImmutable('2026-06-08T11:15:00+00:00'),
        );
        $repository->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T10:30:00+00:00'),
            new DateTimeImmutable('2026-06-08T11:15:00+00:00'),
        );

        self::assertSame(9, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_aggregates'));
        $platformDaily = $repository->query([
            'portal' => 'admin',
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);
        $advertiserHourly = $repository->query([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'granularity' => 'hour',
        ]);

        self::assertCount(1, $platformDaily);
        self::assertSame(1, $platformDaily[0]->impressions);
        self::assertSame(1, $platformDaily[0]->clicks);
        self::assertSame(30, $platformDaily[0]->spendPoints);
        self::assertSame(18, $platformDaily[0]->revenuePoints);
        self::assertCount(2, $advertiserHourly);
        self::assertSame('2026-06-08T10:00:00+00:00', $advertiserHourly[0]->date);
        self::assertSame('2026-06-08T11:00:00+00:00', $advertiserHourly[1]->date);
    }

    public function testAggregateDimensionKeyPreventsDuplicateRowsWhenNullableDimensionsAreNull(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-null-dimensions', '2026-06-08 10:05:00', 10, 6);

        $repository = new DatabaseReportAggregateRepository($connection);
        $repository->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T11:00:00+00:00'),
        );

        $platform = $connection->fetchAssociative(
            "SELECT granularity, bucket_start, dimension_key, organization_role, organization_id, campaign_id
            FROM report_aggregates
            WHERE granularity = 'day' AND organization_role = 'platform'",
        );
        self::assertIsArray($platform);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $connection->insert('report_aggregates', [
            'granularity' => $platform['granularity'],
            'bucket_start' => $platform['bucket_start'],
            'dimension_key' => $platform['dimension_key'],
            'organization_role' => $platform['organization_role'],
            'organization_id' => $platform['organization_id'],
            'campaign_id' => $platform['campaign_id'],
            'site_id' => 10,
            'slot_id' => 20,
            'geo' => null,
            'device' => null,
            'browser' => null,
            'resolution' => null,
            'risk_bucket' => null,
            'impressions' => 1,
            'clicks' => 0,
            'spend_points' => 10,
            'revenue_points' => 6,
            'refreshed_at' => '2026-06-08 11:00:00',
        ]);
    }

    private function createConnection(): Connection
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

        return $connection;
    }

    private function createRawReportingConnection(): Connection
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

        return $connection;
    }

    private function insertEvent(
        Connection $connection,
        string $eventType,
        string $eventId,
        string $occurredAt,
        int $billedPoints,
        int $publisherEarningPoints,
        bool $valid = true,
        string $billingStatus = 'billed',
    ): void {
        $connection->insert('ad_serving_events', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'decision_id' => 'decision-1',
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
            'ad_id' => 'ad-1',
            'campaign_id' => 30,
            'advertiser_organization_id' => 40,
            'publisher_organization_id' => 50,
            'cost_points' => $billedPoints,
            'occurred_at' => $occurredAt,
            'valid' => $valid ? 1 : 0,
            'reason' => $valid ? null : 'repeat_click_window',
            'visible_ratio' => null,
            'visible_ms' => null,
            'billing_status' => $billingStatus,
            'billed_points' => $billingStatus === 'billed' ? $billedPoints : 0,
            'publisher_earning_points' => $billingStatus === 'billed' ? $publisherEarningPoints : 0,
            'billing_reason' => $billingStatus === 'billed' ? null : $billingStatus,
            'billing_processed_at' => $billingStatus === 'billed' ? $occurredAt : null,
            'processed_at' => null,
        ]);
    }

    private function insertConversion(
        Connection $connection,
        string $eventId,
        string $conversionId,
        ?string $clickEventId,
        string $occurredAt,
        string $createdAt,
        int $valuePoints,
        bool $attributed = true,
    ): void {
        $connection->insert('attribution_conversions', [
            'event_id' => $eventId,
            'conversion_id' => $conversionId,
            'organization_id' => 40,
            'oauth_client_id' => 501,
            'recorded_by_user_id' => null,
            'attributed' => $attributed ? 1 : 0,
            'click_event_id' => $clickEventId,
            'decision_id' => 'decision-1',
            'campaign_id' => 30,
            'window_seconds' => 604800,
            'source' => 'server_api',
            'conversion_name' => 'purchase',
            'value_points' => $valuePoints,
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
        ]);
    }
}
