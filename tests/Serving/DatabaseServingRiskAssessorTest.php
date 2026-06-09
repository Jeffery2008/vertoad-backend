<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Service\Serving\AdTrafficRiskDecision;
use VertoAD\Service\Serving\DatabaseServingRiskAssessor;

final class DatabaseServingRiskAssessorTest extends TestCase
{
    public function testRejectsHighRiskViewerBeforeSlotRisk(): void
    {
        $connection = $this->createConnection();
        $this->insertFeature($connection, 'viewer', 'viewer-bot', 'high', 92);
        $this->insertFeature($connection, 'slot', '20', 'high', 88, slotId: 20);

        $decision = (new DatabaseServingRiskAssessor(new DatabaseFraudRiskFeatureRepository($connection)))
            ->assess(10, 20, 'viewer-bot');

        self::assertFalse($decision->allowed);
        self::assertSame('fraud_high_risk_viewer', $decision->reason);
    }

    public function testRejectsHighRiskSlotWhenViewerIsAllowed(): void
    {
        $connection = $this->createConnection();
        $this->insertFeature($connection, 'viewer', 'viewer-normal', 'review', 65);
        $this->insertFeature($connection, 'slot', '20', 'high', 84, slotId: 20);

        $decision = (new DatabaseServingRiskAssessor(new DatabaseFraudRiskFeatureRepository($connection)))
            ->assess(10, 20, 'viewer-normal');

        self::assertFalse($decision->allowed);
        self::assertSame('fraud_high_risk_slot', $decision->reason);
    }

    public function testAllowsMissingAndNonHighRiskFeatures(): void
    {
        $connection = $this->createConnection();
        $this->insertFeature($connection, 'viewer', 'viewer-review', 'review', 70);
        $this->insertFeature($connection, 'slot', '20', 'medium', 45, slotId: 20);

        $decision = (new DatabaseServingRiskAssessor(new DatabaseFraudRiskFeatureRepository($connection)))
            ->assess(10, 20, 'viewer-review');

        self::assertTrue($decision->allowed);
        self::assertNull($decision->reason);
    }

    public function testTrafficRiskDecisionRejectRequiresReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serving risk rejection reason is required.');

        new AdTrafficRiskDecision(false, '');
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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

    private function insertFeature(
        Connection $connection,
        string $scopeType,
        string $scopeId,
        string $riskBucket,
        int $riskScore,
        ?int $slotId = null,
    ): void {
        $connection->insert('fraud_risk_features', [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'window_start' => '2026-06-08 00:00:00',
            'window_end' => '2026-06-08 01:00:00',
            'site_id' => 10,
            'slot_id' => $slotId,
            'viewer_id' => $scopeType === 'viewer' ? $scopeId : null,
            'impressions' => 10,
            'clicks' => 1,
            'invalid_clicks' => 0,
            'ctr_per_mille' => 100,
            'invalid_click_rate_per_mille' => 0,
            'risk_score' => $riskScore,
            'risk_bucket' => $riskBucket,
            'reasons_json' => '[]',
            'computed_at' => '2026-06-08 01:00:00',
        ]);
    }
}

