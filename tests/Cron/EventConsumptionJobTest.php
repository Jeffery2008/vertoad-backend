<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\Cron\InMemoryServingEventBuffer;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\Cron\EventConsumptionJob;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Tests\Billing\BillingTask14Schema;

final class EventConsumptionJobTest extends TestCase
{
    public function testConsumesBufferedEventsBillsValidEventsAndSkipsInvalidEvents(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new InMemoryServingEventBuffer([
            $this->event('impression', 'imp-1', true, 40),
            $this->event('click', 'clk-invalid', false, 80),
            $this->event('click', 'clk-missing-metadata', true, null, publisherOrganizationId: null),
        ]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(3, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame(2, $result->metrics['skipped'] ?? null);
        self::assertSame(0, $result->metrics['duplicates'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(20, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertNotNull((new DatabaseAdEventRepository($connection))->findEvent('impression', 'imp-1'));
    }

    public function testRepeatingConsumptionWindowDoesNotDoubleBillAckedEvents(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new InMemoryServingEventBuffer([$this->event('click', 'clk-1', true, 80)]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $first = $job->run();
        $second = $job->run();

        self::assertSame(1, $first->metrics['billed'] ?? null);
        self::assertSame(0, $second->metrics['consumed'] ?? null);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testDuplicateEventsInSameLeaseAreCountedWithoutDoubleBilling(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new InMemoryServingEventBuffer([
            $this->event('click', 'clk-duplicate', true, 80),
            $this->event('click', 'clk-duplicate', true, 80),
        ]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame(2, $result->metrics['consumed'] ?? null);
        self::assertSame(2, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testEmptyBufferCompletesWithZeroMetrics(): void
    {
        $job = new EventConsumptionJob(
            new InMemoryServingEventBuffer(),
            new DatabaseAdEventRepository($this->createConnection()),
            $this->billingService($this->createConnection()),
            100,
        );

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(0, $result->metrics['consumed'] ?? null);
        self::assertSame(0, $result->metrics['billed'] ?? null);
        self::assertSame(0, $result->metrics['skipped'] ?? null);
        self::assertSame(0, $result->metrics['duplicates'] ?? null);
    }

    public function testBatchLimitLeavesUnleasedEventsPending(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new InMemoryServingEventBuffer([
            $this->event('impression', 'imp-batch-1', true, 40),
            $this->event('impression', 'imp-batch-2', true, 40),
        ]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 1);

        $result = $job->run();

        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertCount(1, $buffer->pending());
        self::assertSame('imp-batch-2', $buffer->pending()[0]->eventId);
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
    }

    public function testInMemoryServingEventBufferRejectsInvalidLeaseLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cron event consume batch size must be positive.');

        (new InMemoryServingEventBuffer())->lease(0);
    }

    public function testPersistFailureLeavesLeasedEventUnacknowledged(): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement('DROP TABLE ad_serving_events');
        $buffer = new InMemoryServingEventBuffer([$this->event('impression', 'imp-persist-fails', true, 40)]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $this->expectException(\Throwable::class);

        try {
            $job->run();
        } finally {
            self::assertCount(1, $buffer->pending());
        }
    }

    private function billingService(Connection $connection): AdEventBillingService
    {
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);

        return new AdEventBillingService(
            new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository),
            new RevenueShareService(new RevenueShareRepository($connection), $ledger),
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE campaign_budget_caps (
    campaign_id INTEGER PRIMARY KEY,
    organization_id INTEGER NOT NULL,
    total_cap_points INTEGER NULL,
    daily_cap_points INTEGER NULL,
    hourly_cap_points INTEGER NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE spend_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reservation_id VARCHAR(160) NOT NULL UNIQUE,
    organization_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    points_amount INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    reserved_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    committed_at DATETIME NULL,
    released_at DATETIME NULL,
    expired_at DATETIME NULL,
    ledger_entry_id INTEGER NULL
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ad_serving_events (
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
)
SQL
        );
        $connection->insert('sites', [
            'id' => 5,
            'organization_id' => 42,
            'name' => 'Publisher',
            'domain' => 'publisher.example',
            'status' => 'verified',
        ]);
        $connection->insert('ad_slots', [
            'id' => 10,
            'site_id' => 5,
            'name' => 'Top',
            'slot_key' => 'top',
            'width' => 300,
            'height' => 250,
            'status' => 'active',
        ]);

        return $connection;
    }

    private function event(
        string $type,
        string $id,
        bool $valid,
        ?int $costPoints,
        ?int $publisherOrganizationId = 42,
    ): AdEvent {
        return new AdEvent(
            eventType: $type,
            eventId: $id,
            decisionId: 'decision-1',
            siteId: 5,
            slotId: 10,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 123,
            advertiserOrganizationId: 99,
            publisherOrganizationId: $publisherOrganizationId,
            costPoints: $costPoints,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: $valid,
            reason: $valid ? null : 'fraud_rejected',
        );
    }
}
