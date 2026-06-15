<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Billing\AdEventBillingResult;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\Cron\InMemoryServingEventBuffer;
use VertoAD\Repository\Cron\ServingEventBufferInterface;
use VertoAD\Repository\Cron\ServingEventPersistenceInterface;
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
        self::assertSame('billed', $this->billingStatus($connection, 'impression', 'imp-1'));
        self::assertSame('skipped', $this->billingStatus($connection, 'click', 'clk-invalid'));
        self::assertSame('skipped', $this->billingStatus($connection, 'click', 'clk-missing-metadata'));
        self::assertSame(40, (int) $connection->fetchOne(
            "SELECT billed_points FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-1'",
        ));
        self::assertSame(20, (int) $connection->fetchOne(
            "SELECT publisher_earning_points FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-1'",
        ));
        self::assertSame(
            ['click:clk-invalid', 'click:clk-missing-metadata', 'impression:imp-1'],
            array_map(static fn ($event): string => $event->eventId, (new DatabaseArchiveRepository($connection))->pendingEvents()),
        );
    }

    public function testMissingRevenueShareRuleSkipsAndAcksBufferedEventsWithoutChargingAdvertiser(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        $buffer = new InMemoryServingEventBuffer([
            $this->event('impression', 'imp-no-share-rule', true, 40),
        ]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(0, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['skipped'] ?? null);
        self::assertSame(0, $result->metrics['failed'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('skipped', $this->billingStatus($connection, 'impression', 'imp-no-share-rule'));
        self::assertSame('missing_revenue_share_rule', $connection->fetchOne(
            "SELECT billing_reason FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-no-share-rule'",
        ));
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
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testDuplicateBufferedEventRepairsPendingDatabaseEventWithoutDoubleBilling(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $event = $this->event('click', 'clk-pending-duplicate', true, 80);
        $persistence = new DatabaseAdEventRepository($connection);
        self::assertTrue($persistence->persist($event));
        $buffer = new InMemoryServingEventBuffer([$event]);
        $job = new EventConsumptionJob($buffer, $persistence, $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('billed', $this->billingStatus($connection, 'click', 'clk-pending-duplicate'));
        self::assertSame(80, (int) $connection->fetchOne(
            "SELECT billed_points FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-pending-duplicate'",
        ));
        self::assertSame(40, (int) $connection->fetchOne(
            "SELECT publisher_earning_points FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-pending-duplicate'",
        ));
        self::assertNotNull($connection->fetchOne(
            "SELECT processed_at FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-pending-duplicate'",
        ));
    }

    public function testDuplicateBufferedEventRepairsPendingSkippedDatabaseEvent(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        $event = $this->event('click', 'clk-pending-skipped-duplicate', true, 80);
        $persistence = new DatabaseAdEventRepository($connection);
        self::assertTrue($persistence->persist($event));
        $buffer = new InMemoryServingEventBuffer([$event]);
        $job = new EventConsumptionJob($buffer, $persistence, $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame(0, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['skipped'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame('skipped', $this->billingStatus($connection, 'click', 'clk-pending-skipped-duplicate'));
        self::assertSame('missing_revenue_share_rule', $connection->fetchOne(
            "SELECT billing_reason FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-pending-skipped-duplicate'",
        ));
        self::assertNotNull($connection->fetchOne(
            "SELECT processed_at FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-pending-skipped-duplicate'",
        ));
    }

    public function testDuplicateEventsAcrossOccurredAtPartitionsAreAckedWithoutDoubleBillingOrDuplicateFacts(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new InMemoryServingEventBuffer([
            $this->event('click', 'clk-cross-partition', true, 80, occurredAt: new DateTimeImmutable('2026-06-08 10:00:00')),
            $this->event('click', 'clk-cross-partition', true, 80, occurredAt: new DateTimeImmutable('2026-07-08 10:00:00')),
        ]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame(2, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame(0, $result->metrics['skipped'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'click' AND event_id = 'clk-cross-partition'",
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM raw_events WHERE event_uuid = 'click:clk-cross-partition'",
        ));
        self::assertSame(['click:clk-cross-partition'], array_map(
            static fn ($event): string => $event->eventId,
            (new DatabaseArchiveRepository($connection))->pendingEvents(),
        ));
    }

    public function testBufferedEventAlreadyBilledByReservationIdIsCountedAsDuplicateWithoutDoubleBilling(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $billing = $this->billingService($connection);
        $event = $this->event('impression', 'imp-already-billed', true, 40);
        $preBilled = $billing->billServingEvent($event);
        $buffer = new InMemoryServingEventBuffer([$event]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $billing, 100);

        $result = $job->run();

        self::assertTrue($preBilled->billed);
        self::assertFalse($preBilled->duplicate);
        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame(1, $result->metrics['duplicates'] ?? null);
        self::assertSame([], $buffer->pending());
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(20, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('billed', $this->billingStatus($connection, 'impression', 'imp-already-billed'));
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

    public function testPersistFailureIsMarkedFailedWithoutStoppingCron(): void
    {
        $connection = $this->createConnection();
        $connection->executeStatement('DROP TABLE ad_serving_events');
        $buffer = new InMemoryServingEventBuffer([$this->event('impression', 'imp-persist-fails', true, 40)]);
        $job = new EventConsumptionJob($buffer, new DatabaseAdEventRepository($connection), $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['failed'] ?? null);
        self::assertSame(0, $result->metrics['billed'] ?? null);
        self::assertSame([], $buffer->pending());
    }

    public function testFailedEventIsRecordedAndDoesNotStopBatchConsumption(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new FailingAwareBuffer([
            $this->event('impression', 'imp-poison', true, 40),
            $this->event('impression', 'imp-good', true, 40),
        ]);
        $persistence = new ThrowingAdEventPersistence(
            new DatabaseAdEventRepository($connection),
            static fn (AdEvent $event): bool => $event->eventId === 'imp-poison',
        );
        $job = new EventConsumptionJob($buffer, $persistence, $this->billingService($connection), 100);

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(2, $result->metrics['consumed'] ?? null);
        self::assertSame(1, $result->metrics['failed'] ?? null);
        self::assertSame(1, $result->metrics['billed'] ?? null);
        self::assertSame(['impression:imp-poison'], $buffer->failedKeys());
        self::assertSame([], $buffer->pending());
        self::assertNotNull((new DatabaseAdEventRepository($connection))->findEvent('impression', 'imp-good'));
        self::assertNull((new DatabaseAdEventRepository($connection))->findEvent('impression', 'imp-poison'));
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
    }

    public function testBillingResultWriteFailureMarksPersistedEventFailed(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new FailingAwareBuffer([$this->event('impression', 'imp-result-fails', true, 40)]);
        $job = new EventConsumptionJob(
            $buffer,
            new BillingResultFailingPersistence(new DatabaseAdEventRepository($connection)),
            $this->billingService($connection),
            100,
        );

        $result = $job->run();

        self::assertSame(1, $result->metrics['failed'] ?? null);
        self::assertSame(['impression:imp-result-fails'], $buffer->failedKeys());
        self::assertSame('failed', $this->billingStatus($connection, 'impression', 'imp-result-fails'));
        self::assertSame('simulated billing result write failure', $connection->fetchOne(
            "SELECT billing_reason FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-result-fails'",
        ));
    }

    public function testRetryAfterBillingResultWriteFailureRepairsEventStatusWithoutDoubleBilling(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $buffer = new StickyFailingBuffer([$this->event('impression', 'imp-retry-result', true, 40)]);

        $first = new EventConsumptionJob(
            $buffer,
            new BillingResultFailingPersistence(new DatabaseAdEventRepository($connection)),
            $this->billingService($connection),
            100,
        );
        $second = new EventConsumptionJob(
            $buffer,
            new DatabaseAdEventRepository($connection),
            $this->billingService($connection),
            100,
        );

        $firstResult = $first->run();
        $secondResult = $second->run();

        self::assertSame(1, $firstResult->metrics['failed'] ?? null);
        self::assertSame(1, $secondResult->metrics['billed'] ?? null);
        self::assertSame(1, $secondResult->metrics['duplicates'] ?? null);
        self::assertSame('billed', $this->billingStatus($connection, 'impression', 'imp-retry-result'));
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(20, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame([], $buffer->pending());
        self::assertSame(40, (int) $connection->fetchOne(
            "SELECT billed_points FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-retry-result'",
        ));
        self::assertSame(20, (int) $connection->fetchOne(
            "SELECT publisher_earning_points FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-retry-result'",
        ));
        self::assertNotNull($connection->fetchOne(
            "SELECT processed_at FROM ad_serving_events WHERE event_type = 'impression' AND event_id = 'imp-retry-result'",
        ));
    }

    private function billingService(Connection $connection): AdEventBillingService
    {
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);

        return new AdEventBillingService(
            new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository),
            new RevenueShareService(new RevenueShareRepository($connection), $ledger),
            $connection,
        );
    }

    private function billingStatus(Connection $connection, string $eventType, string $eventId): string
    {
        return (string) $connection->fetchOne(
            'SELECT billing_status FROM ad_serving_events WHERE event_type = ? AND event_id = ?',
            [$eventType, $eventId],
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
        ?DateTimeImmutable $occurredAt = null,
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
            occurredAt: $occurredAt ?? new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: $valid,
            reason: $valid ? null : 'fraud_rejected',
        );
    }
}

final class FailingAwareBuffer implements ServingEventBufferInterface
{
    /** @var list<AdEvent> */
    private array $events;
    /** @var list<string> */
    private array $failed = [];

    /** @param list<AdEvent> $events */
    public function __construct(array $events)
    {
        $this->events = array_values($events);
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        return array_slice($this->events, 0, $limit);
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->remove($event);
    }

    public function fail(AdEvent $event, \Throwable $reason): void
    {
        $this->failed[] = $event->eventType . ':' . $event->eventId;
        $this->remove($event);
    }

    /** @return list<AdEvent> */
    public function pending(): array
    {
        return $this->events;
    }

    /** @return list<string> */
    public function failedKeys(): array
    {
        return $this->failed;
    }

    private function remove(AdEvent $event): void
    {
        $key = $event->eventType . ':' . $event->eventId;
        $this->events = array_values(array_filter(
            $this->events,
            static fn (AdEvent $pending): bool => $pending->eventType . ':' . $pending->eventId !== $key,
        ));
    }
}

final readonly class ThrowingAdEventPersistence implements ServingEventPersistenceInterface
{
    /** @param callable(AdEvent): bool $shouldThrow */
    public function __construct(
        private DatabaseAdEventRepository $inner,
        private \Closure $shouldThrow,
    )
    {
    }

    public function persist(AdEvent $event): bool
    {
        if (($this->shouldThrow)($event)) {
            throw new \RuntimeException('simulated poison event');
        }

        return $this->inner->persist($event);
    }

    public function findPendingDuplicate(AdEvent $event): ?AdEvent
    {
        return $this->inner->findPendingDuplicate($event);
    }

    public function recordBillingResult(AdEvent $event, AdEventBillingResult $result, \DateTimeImmutable $processedAt): void
    {
        $this->inner->recordBillingResult($event, $result, $processedAt);
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->inner->acknowledge($event);
    }

    public function recordFailure(AdEvent $event, \Throwable $reason): void
    {
        $this->inner->recordFailure($event, $reason);
    }
}

final readonly class BillingResultFailingPersistence implements ServingEventPersistenceInterface
{
    public function __construct(private DatabaseAdEventRepository $inner)
    {
    }

    public function persist(AdEvent $event): bool
    {
        return $this->inner->persist($event);
    }

    public function findPendingDuplicate(AdEvent $event): ?AdEvent
    {
        return $this->inner->findPendingDuplicate($event);
    }

    public function recordBillingResult(AdEvent $event, AdEventBillingResult $result, \DateTimeImmutable $processedAt): void
    {
        throw new \RuntimeException('simulated billing result write failure');
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->inner->acknowledge($event);
    }

    public function recordFailure(AdEvent $event, \Throwable $reason): void
    {
        $this->inner->recordFailure($event, $reason);
    }
}

final class StickyFailingBuffer implements ServingEventBufferInterface
{
    /** @var list<AdEvent> */
    private array $events;

    /** @param list<AdEvent> $events */
    public function __construct(array $events)
    {
        $this->events = array_values($events);
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        return array_slice($this->events, 0, $limit);
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->remove($event);
    }

    public function fail(AdEvent $event, \Throwable $reason): void
    {
    }

    /** @return list<AdEvent> */
    public function pending(): array
    {
        return $this->events;
    }

    private function remove(AdEvent $event): void
    {
        $key = $event->eventType . ':' . $event->eventId;
        $this->events = array_values(array_filter(
            $this->events,
            static fn (AdEvent $pending): bool => $pending->eventType . ':' . $pending->eventId !== $key,
        ));
    }
}
