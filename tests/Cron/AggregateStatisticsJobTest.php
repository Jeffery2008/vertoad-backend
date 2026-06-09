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
        $this->insertEvent($connection, 'impression', 'imp-1', '2026-06-08 10:05:00', 10);
        $this->insertEvent($connection, 'click', 'clk-1', '2026-06-08 10:15:00', 20);
        $this->insertEvent($connection, 'click', 'clk-invalid', '2026-06-08 10:20:00', 20, valid: false);

        $job = new AggregateStatisticsJob(
            new DatabaseReportAggregateRepository($connection),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );

        $first = $job->run();
        $second = $job->run();

        self::assertSame('aggregate-statistics', $first->jobName);
        self::assertSame('completed', $first->status);
        self::assertSame(['day_rows' => 2, 'hour_rows' => 2], $first->metrics);
        self::assertSame(['day_rows' => 2, 'hour_rows' => 2], $second->metrics);

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
            'organization_id' => 50,
            'granularity' => 'hour',
        ]);

        self::assertCount(1, $daily);
        self::assertSame('2026-06-08', $daily[0]->date);
        self::assertSame(1, $daily[0]->impressions);
        self::assertSame(1, $daily[0]->clicks);
        self::assertSame(10, $daily[0]->spendPoints);
        self::assertSame(20, $daily[0]->revenuePoints);

        self::assertCount(1, $hourly);
        self::assertSame('2026-06-08T10:00:00+00:00', $hourly[0]->date);
        self::assertSame(1, $hourly[0]->impressions);
        self::assertSame(1, $hourly[0]->clicks);
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
                processed_at DATETIME NULL
            )',
        );

        $this->expectException(TableNotFoundException::class);

        (new DatabaseReportAggregateRepository($connection))->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );
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
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE report_aggregates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                granularity VARCHAR(12) NOT NULL,
                bucket_start DATETIME NOT NULL,
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
                refreshed_at DATETIME NOT NULL
            )',
        );

        return $connection;
    }

    private function insertEvent(
        Connection $connection,
        string $eventType,
        string $eventId,
        string $occurredAt,
        int $costPoints,
        bool $valid = true,
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
            'cost_points' => $costPoints,
            'occurred_at' => $occurredAt,
            'valid' => $valid ? 1 : 0,
            'reason' => $valid ? null : 'repeat_click_window',
            'visible_ratio' => null,
            'visible_ms' => null,
            'processed_at' => null,
        ]);
    }
}
