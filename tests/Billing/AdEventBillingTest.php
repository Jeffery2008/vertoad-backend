<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Domain\Budget\SpendReservationTransition;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class AdEventBillingTest extends TestCase
{
    public function testBillsValidImpressionAndCreditsPublisherShareOnce(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 6000, null, new DateTimeImmutable('2026-06-08 09:00:00'));

        $service = $this->createService($connection);
        $event = $this->event('impression', 'imp-1', 40);

        $first = $service->bill($event);
        $duplicate = $service->bill($event);

        self::assertTrue($first->billed);
        self::assertFalse($first->duplicate);
        self::assertSame(40, $first->grossPoints);
        self::assertSame(24, $first->publisherPoints);
        self::assertTrue($duplicate->billed);
        self::assertTrue($duplicate->duplicate);
        self::assertSame(960, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(24, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testBillsValidClickUsingClickCostAndKeepsSeparateIdempotency(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('slot', null, null, 10, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));

        $result = $this->createService($connection)->bill($this->event('click', 'clk-1', 125));

        self::assertTrue($result->billed);
        self::assertSame(125, $result->grossPoints);
        self::assertSame(62, $result->publisherPoints);
        self::assertSame(875, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(62, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testBillsServingEventSnapshotWhenAllBillingMetadataExists(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));

        $result = $this->createService($connection)->billServingEvent(new AdEvent(
            eventType: 'click',
            eventId: 'clk-buffered',
            decisionId: 'decision-1',
            siteId: 5,
            slotId: 10,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 123,
            advertiserOrganizationId: 99,
            publisherOrganizationId: 42,
            costPoints: 80,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: true,
            reason: null,
        ));

        self::assertTrue($result->billed);
        self::assertSame(80, $result->grossPoints);
        self::assertSame(40, $result->publisherPoints);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testServingEventSnapshotDuplicateIsIdempotent(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $event = new AdEvent(
            eventType: 'click',
            eventId: 'clk-buffered-duplicate',
            decisionId: 'decision-1',
            siteId: 5,
            slotId: 10,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 123,
            advertiserOrganizationId: 99,
            publisherOrganizationId: 42,
            costPoints: 80,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: true,
            reason: null,
        );
        $service = $this->createService($connection);

        $first = $service->billServingEvent($event);
        $second = $service->billServingEvent($event);

        self::assertTrue($first->billed);
        self::assertFalse($first->duplicate);
        self::assertTrue($second->billed);
        self::assertTrue($second->duplicate);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(40, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testSameEventIdAcrossDifferentDecisionsDoesNotShareBillingIdempotency(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser-a');
        $ledger->credit(100, 'advertiser_balance', null, 1_000, 'recharge:advertiser-b');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $service = $this->createService($connection);

        $first = $service->bill($this->event('click', 'shared-event-id', 80, decisionId: 'decision-a', advertiserOrganizationId: 99, campaignId: 123));
        $second = $service->bill($this->event('click', 'shared-event-id', 120, decisionId: 'decision-b', advertiserOrganizationId: 100, campaignId: 124));

        self::assertTrue($first->billed);
        self::assertTrue($second->billed);
        self::assertFalse($second->duplicate);
        self::assertSame(920, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(880, $ledgerRepository->balanceForOrganization(100));
        self::assertSame(100, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM spend_reservations'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_earning_events'));
    }

    public function testSkipsServingEventSnapshotMissingBillingMetadata(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');

        $result = $this->createService($connection)->billServingEvent(new AdEvent(
            eventType: 'click',
            eventId: 'clk-missing-metadata',
            decisionId: 'decision-1',
            siteId: 5,
            slotId: 10,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 123,
            advertiserOrganizationId: 99,
            publisherOrganizationId: null,
            costPoints: 80,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: true,
            reason: null,
        ));

        self::assertFalse($result->billed);
        self::assertSame('missing_billing_metadata', $result->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
    }

    public function testSkipsNonBillableVideoServingEvents(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');

        $result = $this->createService($connection)->billServingEvent(new AdEvent(
            eventType: 'video_complete',
            eventId: 'video-complete-billing',
            decisionId: 'decision-1',
            siteId: 5,
            slotId: 10,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 123,
            advertiserOrganizationId: 99,
            publisherOrganizationId: 42,
            costPoints: 0,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
            valid: true,
            reason: null,
        ));

        self::assertFalse($result->billed);
        self::assertSame('non_billable_event', $result->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testDoesNotBillInvalidOrZeroCostEvents(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 6000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $service = $this->createService($connection);

        $invalid = $service->bill($this->event('click', 'invalid-click', 125, valid: false));
        $free = $service->bill($this->event('impression', 'free-impression', 0));

        self::assertFalse($invalid->billed);
        self::assertSame('invalid_event', $invalid->reason);
        self::assertFalse($free->billed);
        self::assertSame('zero_cost', $free->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testBudgetRejectionPreventsAdvertiserDebitAndPublisherCredit(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(99, 'advertiser_balance', null, 20, 'recharge:too-small');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 6000, null, new DateTimeImmutable('2026-06-08 09:00:00'));

        $result = $this->createService($connection)->bill($this->event('click', 'clk-expensive', 125));

        self::assertFalse($result->billed);
        self::assertSame('insufficient_balance', $result->reason);
        self::assertSame(20, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testMissingRevenueShareRuleSkipsBeforeAdvertiserDebit(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');

        $result = $this->createService($connection)->bill($this->event('impression', 'imp-no-share-rule', 40));

        self::assertFalse($result->billed);
        self::assertSame('missing_revenue_share_rule', $result->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM spend_reservations'));
    }

    public function testZeroPublisherEarningSkipsBeforeAdvertiserDebit(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 0, null, new DateTimeImmutable('2026-06-08 09:00:00'));

        $result = $this->createService($connection)->bill($this->event('impression', 'imp-zero-share', 40));

        self::assertFalse($result->billed);
        self::assertSame('zero_publisher_earning', $result->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM spend_reservations'));
    }

    public function testPublisherEarningFailureRollsBackAdvertiserDebit(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $connection->executeStatement(
            <<<'SQL'
CREATE TRIGGER fail_publisher_earning_insert
BEFORE INSERT ON publisher_earning_events
BEGIN
    SELECT RAISE(FAIL, 'publisher earning insert failed');
END
SQL
        );

        try {
            $this->createService($connection)->bill($this->event('impression', 'imp-earning-fails', 40));
            self::fail('Expected publisher earning persistence failure.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('publisher earning insert failed', $exception->getMessage());
        }

        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM spend_reservations WHERE status = 'committed'"));
    }

    public function testCommitRejectionPreventsPublisherCredit(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 1_000, 'recharge:advertiser');
        (new RevenueShareRepository($connection))->createRule('global', null, null, null, 6000, null, new DateTimeImmutable('2026-06-08 09:00:00'));
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new AdEventBillingService(
            new CampaignBudgetService($this->commitRejectingBudgetRepository(), $ledger, $ledgerRepository),
            new RevenueShareService(new RevenueShareRepository($connection), $ledger),
        );

        $result = $service->bill($this->event('click', 'commit-rejected', 80));

        self::assertFalse($result->billed);
        self::assertSame('duplicate_state', $result->reason);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
    }

    public function testBillableAdEventRejectsInvalidConstructionArguments(): void
    {
        $this->assertInvalidBillableEvent(
            static fn (): BillableAdEvent => new BillableAdEvent('conversion', 'evt', 'decision', 5, 10, 42, 99, 123, 'ad-1', 'viewer', 10, true, new DateTimeImmutable()),
            'Billable ad event type is invalid.',
        );
        $this->assertInvalidBillableEvent(
            static fn (): BillableAdEvent => new BillableAdEvent('click', ' ', 'decision', 5, 10, 42, 99, 123, 'ad-1', 'viewer', 10, true, new DateTimeImmutable()),
            'Billable ad event id is required.',
        );
        $this->assertInvalidBillableEvent(
            static fn (): BillableAdEvent => new BillableAdEvent('click', 'evt', 'decision', 0, 10, 42, 99, 123, 'ad-1', 'viewer', 10, true, new DateTimeImmutable()),
            'Billable ad event inventory identifiers must be positive.',
        );
        $this->assertInvalidBillableEvent(
            static fn (): BillableAdEvent => new BillableAdEvent('click', 'evt', 'decision', 5, 10, 0, 99, 123, 'ad-1', 'viewer', 10, true, new DateTimeImmutable()),
            'Billable ad event organization and campaign identifiers must be positive.',
        );
        $this->assertInvalidBillableEvent(
            static fn (): BillableAdEvent => new BillableAdEvent('click', 'evt', 'decision', 5, 10, 42, 99, 123, 'ad-1', 'viewer', -1, true, new DateTimeImmutable()),
            'Billable ad event cost points cannot be negative.',
        );
    }

    private function createService(Connection $connection): AdEventBillingService
    {
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);

        return new AdEventBillingService(
            new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository),
            new RevenueShareService(new RevenueShareRepository($connection), $ledger),
            $connection,
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $this->createBudgetTables($connection);
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

    private function createBudgetTables(Connection $connection): void
    {
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
    }

    private function event(
        string $type,
        string $id,
        int $costPoints,
        bool $valid = true,
        string $decisionId = 'decision-1',
        int $advertiserOrganizationId = 99,
        int $campaignId = 123,
    ): BillableAdEvent
    {
        return new BillableAdEvent(
            eventType: $type,
            eventId: $id,
            decisionId: $decisionId,
            siteId: 5,
            slotId: 10,
            publisherOrganizationId: 42,
            advertiserOrganizationId: $advertiserOrganizationId,
            campaignId: $campaignId,
            adId: 'ad-1',
            viewerId: 'viewer-1',
            costPoints: $costPoints,
            valid: $valid,
            occurredAt: new DateTimeImmutable('2026-06-08 10:00:00'),
        );
    }

    private function assertInvalidBillableEvent(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            return;
        }

        self::fail('Expected invalid billable ad event.');
    }

    private function commitRejectingBudgetRepository(): CampaignBudgetRepositoryInterface
    {
        return new class implements CampaignBudgetRepositoryInterface {
            private ?SpendReservation $reservation = null;

            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function saveCaps(CampaignBudgetCaps $caps): CampaignBudgetCaps
            {
                return $caps;
            }

            public function findCaps(int $organizationId, int $campaignId): ?CampaignBudgetCaps
            {
                return null;
            }

            public function findReservation(string $reservationId): ?SpendReservation
            {
                return $this->reservation;
            }

            public function lockBudgetScope(int $organizationId, int $campaignId): void
            {
            }

            public function createReservation(SpendReservation $reservation): SpendReservation
            {
                $this->reservation = new SpendReservation(
                    id: 1,
                    reservationId: $reservation->reservationId,
                    organizationId: $reservation->organizationId,
                    campaignId: $reservation->campaignId,
                    pointsAmount: $reservation->pointsAmount,
                    status: SpendReservationStatus::Released,
                    reservedAt: $reservation->reservedAt,
                    expiresAt: $reservation->expiresAt,
                    committedAt: null,
                    releasedAt: $reservation->reservedAt,
                    expiredAt: null,
                    ledgerEntryId: null,
                );

                return $reservation;
            }

            public function markCommitted(string $reservationId, int $ledgerEntryId, DateTimeImmutable $committedAt): SpendReservationTransition
            {
                return new SpendReservationTransition(false, $this->reservation);
            }

            public function markReleased(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationTransition
            {
                return new SpendReservationTransition(false, $this->reservation);
            }

            public function markExpired(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationTransition
            {
                return new SpendReservationTransition(false, $this->reservation);
            }

            public function activeReservedSpendForCampaign(int $organizationId, int $campaignId, DateTimeImmutable $at): int
            {
                return 0;
            }

            public function committedSpendForCampaign(int $organizationId, int $campaignId): int
            {
                return 0;
            }

            public function activeReservedSpendForCampaignWindow(
                int $organizationId,
                int $campaignId,
                DateTimeImmutable $windowStart,
                DateTimeImmutable $windowEnd,
                DateTimeImmutable $at,
            ): int {
                return 0;
            }

            public function committedSpendForCampaignWindow(
                int $organizationId,
                int $campaignId,
                DateTimeImmutable $windowStart,
                DateTimeImmutable $windowEnd,
            ): int {
                return 0;
            }

            public function activeReservedSpendForOrganization(int $organizationId, DateTimeImmutable $at): int
            {
                return 0;
            }
        };
    }
}
