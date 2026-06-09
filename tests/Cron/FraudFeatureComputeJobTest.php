<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Service\Cron\FraudFeatureComputeJob;

final class FraudFeatureComputeJobTest extends TestCase
{
    public function testComputesViewerAndSlotRiskFeaturesIdempotently(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-bot', 'viewer-bot', '2026-06-08 10:00:00');
        $this->insertEvent($connection, 'click', 'clk-bot-valid', 'viewer-bot', '2026-06-08 10:01:00', costPoints: 25);
        $this->insertEvent($connection, 'click', 'clk-bot-invalid-1', 'viewer-bot', '2026-06-08 10:02:00', valid: false, reason: 'repeat_click_window');
        $this->insertEvent($connection, 'click', 'clk-bot-invalid-2', 'viewer-bot', '2026-06-08 10:03:00', valid: false, reason: 'click_without_impression');
        $this->insertEvent($connection, 'impression', 'imp-normal-1', 'viewer-normal', '2026-06-08 10:04:00');
        $this->insertEvent($connection, 'impression', 'imp-normal-2', 'viewer-normal', '2026-06-08 10:05:00');
        $this->insertEvent($connection, 'impression', 'imp-normal-3', 'viewer-normal', '2026-06-08 10:06:00');
        $this->insertEvent($connection, 'click', 'clk-normal', 'viewer-normal', '2026-06-08 10:07:00', costPoints: 25);
        $this->insertEvent($connection, 'click', 'clk-outside', 'viewer-outside', '2026-06-09 12:00:00', valid: false, reason: 'outside_window');

        $repository = new DatabaseFraudRiskFeatureRepository($connection);
        $job = new FraudFeatureComputeJob(
            $repository,
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );

        $first = $job->run();
        $second = $job->run();

        self::assertSame('fraud-feature-compute', $first->jobName);
        self::assertSame('completed', $first->status);
        self::assertSame([
            'viewer_rows' => 2,
            'slot_rows' => 1,
            'high_risk_rows' => 2,
        ], $first->metrics);
        self::assertSame($first->metrics, $second->metrics);
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM fraud_risk_features'));

        $viewerFeature = $repository->latestForScope('viewer', 'viewer-bot');
        $normalFeature = $repository->latestForScope('viewer', 'viewer-normal');
        $slotFeature = $repository->latestForScope('slot', '20');

        self::assertNotNull($viewerFeature);
        self::assertNotNull($normalFeature);
        self::assertNotNull($slotFeature);
        self::assertSame('high', $viewerFeature->riskBucket);
        self::assertSame(90, $viewerFeature->riskScore);
        self::assertSame(1, $viewerFeature->impressions);
        self::assertSame(3, $viewerFeature->clicks);
        self::assertSame(2, $viewerFeature->invalidClicks);
        self::assertContains('invalid_click_rate', $viewerFeature->reasons);
        self::assertSame('low', $normalFeature->riskBucket);
        self::assertSame('high', $slotFeature->riskBucket);
    }

    public function testRejectsInvalidFeatureWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fraud feature compute end time must be after start time.');

        new FraudFeatureComputeJob(
            new DatabaseFraudRiskFeatureRepository($this->createConnection()),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
        );
    }

    public function testRepositoryRejectsInvalidRefreshWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fraud feature refresh end time must be after start time.');

        (new DatabaseFraudRiskFeatureRepository($this->createConnection()))->refreshFromEvents(
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
        );
    }

    public function testRepositoryReturnsNullWhenScopeHasNoComputedFeature(): void
    {
        self::assertNull((new DatabaseFraudRiskFeatureRepository($this->createConnection()))->latestForScope('viewer', 'missing'));
    }

    public function testRepositoryRollsBackWhenFeatureTableIsMissing(): void
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
        $this->insertEvent($connection, 'click', 'clk-missing-table', 'viewer-missing-table', '2026-06-08 10:00:00');

        $this->expectException(TableNotFoundException::class);

        (new DatabaseFraudRiskFeatureRepository($connection))->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );
    }

    public function testScoresClickOnlyAndModerateInvalidClickFeatures(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'click', 'clk-click-only', 'viewer-click-only', '2026-06-08 10:00:00');
        $this->insertEvent($connection, 'impression', 'imp-invalid-1', 'viewer-invalid', '2026-06-08 10:01:00');
        $this->insertEvent($connection, 'impression', 'imp-invalid-2', 'viewer-invalid', '2026-06-08 10:02:00');
        $this->insertEvent($connection, 'impression', 'imp-invalid-3', 'viewer-invalid', '2026-06-08 10:03:00');
        $this->insertEvent($connection, 'impression', 'imp-invalid-4', 'viewer-invalid', '2026-06-08 10:04:00');
        $this->insertEvent($connection, 'click', 'clk-invalid-valid-1', 'viewer-invalid', '2026-06-08 10:05:00');
        $this->insertEvent($connection, 'click', 'clk-invalid-valid-2', 'viewer-invalid', '2026-06-08 10:06:00');
        $this->insertEvent($connection, 'click', 'clk-invalid-moderate', 'viewer-invalid', '2026-06-08 10:07:00', valid: false, reason: 'repeat_click_window');
        $this->insertEvent($connection, 'impression', 'imp-elevated', 'viewer-elevated', '2026-06-08 10:08:00', slotId: 21);
        $this->insertEvent($connection, 'click', 'clk-elevated', 'viewer-elevated', '2026-06-08 10:09:00', slotId: 21);

        $repository = new DatabaseFraudRiskFeatureRepository($connection);
        $repository->refreshFromEvents(
            new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );

        $clickOnly = $repository->latestForScope('viewer', 'viewer-click-only');
        $invalid = $repository->latestForScope('viewer', 'viewer-invalid');
        $elevated = $repository->latestForScope('viewer', 'viewer-elevated');

        self::assertNotNull($clickOnly);
        self::assertNotNull($invalid);
        self::assertNotNull($elevated);
        self::assertSame(95, $clickOnly->riskScore);
        self::assertSame('click_without_impression', $clickOnly->reasons[0]);
        self::assertSame(70, $invalid->riskScore);
        self::assertSame('review', $invalid->riskBucket);
        self::assertContains('invalid_clicks', $invalid->reasons);
        self::assertSame(60, $elevated->riskScore);
        self::assertContains('elevated_ctr', $elevated->reasons);
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
            'CREATE TABLE fraud_risk_features (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_type VARCHAR(24) NOT NULL,
                scope_id VARCHAR(160) NOT NULL,
                window_start DATETIME NOT NULL,
                window_end DATETIME NOT NULL,
                site_id INTEGER NULL,
                slot_id INTEGER NULL,
                viewer_id VARCHAR(160) NULL,
                impressions INTEGER NOT NULL,
                clicks INTEGER NOT NULL,
                invalid_clicks INTEGER NOT NULL,
                ctr_per_mille INTEGER NOT NULL,
                invalid_click_rate_per_mille INTEGER NOT NULL,
                risk_score INTEGER NOT NULL,
                risk_bucket VARCHAR(24) NOT NULL,
                reasons_json TEXT NOT NULL,
                computed_at DATETIME NOT NULL
            )',
        );

        return $connection;
    }

    private function insertEvent(
        Connection $connection,
        string $eventType,
        string $eventId,
        string $viewerId,
        string $occurredAt,
        int $costPoints = 0,
        bool $valid = true,
        ?string $reason = null,
        int $slotId = 20,
    ): void {
        $connection->insert('ad_serving_events', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'decision_id' => 'decision-' . $eventId,
            'site_id' => 10,
            'slot_id' => $slotId,
            'viewer_id' => $viewerId,
            'ad_id' => 'ad-1',
            'campaign_id' => 30,
            'advertiser_organization_id' => 40,
            'publisher_organization_id' => 50,
            'cost_points' => $costPoints,
            'occurred_at' => $occurredAt,
            'valid' => $valid ? 1 : 0,
            'reason' => $reason,
            'visible_ratio' => null,
            'visible_ms' => null,
            'processed_at' => null,
        ]);
    }
}
