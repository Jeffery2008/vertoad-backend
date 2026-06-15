<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Domain\Budget\SpendReservationTransition;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class SpendReservationTest extends TestCase
{
    public function testSpendReservationsRejectInvalidIdentifiersAmountsAndLedgerEntries(): void
    {
        $reservedAt = new DateTimeImmutable('2026-06-07 12:00:00');
        $expiresAt = new DateTimeImmutable('2026-06-07 12:01:00');

        $this->assertInvalidReservation(
            static fn (): SpendReservation => new SpendReservation(null, ' ', 10, 20, 100, SpendReservationStatus::Reserved, $reservedAt, $expiresAt, null, null, null, null),
            'Spend reservation ID is required.',
        );
        $this->assertInvalidReservation(
            static fn (): SpendReservation => new SpendReservation(null, 'spend:invalid', 0, 20, 100, SpendReservationStatus::Reserved, $reservedAt, $expiresAt, null, null, null, null),
            'Spend reservation organization ID must be positive.',
        );
        $this->assertInvalidReservation(
            static fn (): SpendReservation => new SpendReservation(null, 'spend:invalid', 10, 0, 100, SpendReservationStatus::Reserved, $reservedAt, $expiresAt, null, null, null, null),
            'Spend reservation campaign ID must be positive.',
        );
        $this->assertInvalidReservation(
            static fn (): SpendReservation => new SpendReservation(null, 'spend:invalid', 10, 20, 0, SpendReservationStatus::Reserved, $reservedAt, $expiresAt, null, null, null, null),
            'Spend reservation points amount must be positive.',
        );
        $this->assertInvalidReservation(
            static fn (): SpendReservation => new SpendReservation(null, 'spend:invalid', 10, 20, 100, SpendReservationStatus::Reserved, $reservedAt, $expiresAt, null, null, null, 0),
            'Spend reservation ledger entry ID must be positive when provided.',
        );
    }

    public function testReserveCommitReleaseAndExpireAreIdempotentByReservationId(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:reservations');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, 1_000, 1_000, 1_000));

        $reserved = $service->reserve(10, 20, 'spend:r1', 250, new DateTimeImmutable('2026-06-07 12:00:00'), 60);
        self::assertTrue($reserved->accepted);
        self::assertSame(SpendReservationStatus::Reserved, $reserved->reservation?->status);

        $reservedAgain = $service->reserve(10, 20, 'spend:r1', 250, new DateTimeImmutable('2026-06-07 12:00:00'), 60);
        self::assertTrue($reservedAgain->accepted);
        self::assertSame($reserved->reservation?->id, $reservedAgain->reservation?->id);

        $committed = $service->commit('spend:r1', new DateTimeImmutable('2026-06-07 12:00:30'));
        self::assertTrue($committed->accepted);
        self::assertSame(SpendReservationStatus::Committed, $committed->reservation?->status);
        self::assertSame(750, $ledgerRepository->balanceForOrganization(10));

        $committedAgain = $service->commit('spend:r1', new DateTimeImmutable('2026-06-07 12:00:31'));
        self::assertTrue($committedAgain->accepted);
        self::assertSame($committed->reservation?->ledgerEntryId, $committedAgain->reservation?->ledgerEntryId);
        self::assertSame(750, $ledgerRepository->balanceForOrganization(10));

        $releaseCommitted = $service->release('spend:r1', new DateTimeImmutable('2026-06-07 12:01:00'));
        self::assertFalse($releaseCommitted->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $releaseCommitted->failureReason);

        $toRelease = $service->reserve(10, 20, 'spend:r2', 100, new DateTimeImmutable('2026-06-07 12:02:00'), 60);
        self::assertTrue($toRelease->accepted);
        $released = $service->release('spend:r2', new DateTimeImmutable('2026-06-07 12:02:30'));
        self::assertTrue($released->accepted);
        self::assertSame(SpendReservationStatus::Released, $released->reservation?->status);

        $toExpire = $service->reserve(10, 20, 'spend:r3', 100, new DateTimeImmutable('2026-06-07 12:03:00'), 10);
        self::assertTrue($toExpire->accepted);
        $expired = $service->expire('spend:r3', new DateTimeImmutable('2026-06-07 12:03:11'));
        self::assertTrue($expired->accepted);
        self::assertSame(SpendReservationStatus::Expired, $expired->reservation?->status);

        $commitExpired = $service->commit('spend:r3', new DateTimeImmutable('2026-06-07 12:03:12'));
        self::assertFalse($commitExpired->accepted);
        self::assertSame(SpendFailureReason::ExpiredReservation, $commitExpired->failureReason);
    }

    public function testReservationTimesArePersistedAndHydratedAsUtcWhenDefaultTimezoneDiffers(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        try {
            $connection = $this->createConnection();
            $budgetRepository = new CampaignBudgetRepository($connection);
            $ledgerRepository = new PointsLedgerRepository($connection);
            $ledger = new PointsLedgerService($ledgerRepository);
            $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

            $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:reservation-utc');

            $reserved = $service->reserve(
                10,
                20,
                'spend:utc',
                250,
                new DateTimeImmutable('2026-06-08T02:00:00+00:00'),
                300,
            );
            $committed = $service->commit('spend:utc', new DateTimeImmutable('2026-06-08T02:04:00+00:00'));

            self::assertTrue($reserved->accepted);
            self::assertTrue($committed->accepted);
            self::assertSame(SpendReservationStatus::Committed, $committed->reservation?->status);
            self::assertSame(750, $ledgerRepository->balanceForOrganization(10));
            self::assertSame('2026-06-08 02:00:00', $connection->fetchOne(
                "SELECT reserved_at FROM spend_reservations WHERE reservation_id = 'spend:utc'",
            ));
            self::assertSame('2026-06-08 02:04:00', $connection->fetchOne(
                "SELECT committed_at FROM spend_reservations WHERE reservation_id = 'spend:utc'",
            ));
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function testReserveRejectsInvalidTtlAndDuplicateReservationMismatches(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $this->assertInvalidReservationOperation(
            static fn () => $service->reserve(10, 20, 'spend:ttl', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 0),
            'Spend reservation TTL seconds must be positive.',
        );

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:duplicate-reservation');
        self::assertTrue($service->reserve(10, 20, 'spend:duplicate', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);

        $duplicate = $service->reserve(10, 20, 'spend:duplicate', 101, new DateTimeImmutable('2026-06-07 12:00:00'), 60);

        self::assertFalse($duplicate->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $duplicate->failureReason);
    }

    public function testReserveAllowsCampaignsWithoutExplicitCaps(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:uncapped-reservation');

        $reserved = $service->reserve(10, 20, 'spend:uncapped', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60);

        self::assertTrue($reserved->accepted);
        self::assertSame(SpendReservationStatus::Reserved, $reserved->reservation?->status);
    }

    public function testCommitRejectsMissingExpiredReleasedAndUnfundedReservations(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $missing = $service->commit('spend:missing', new DateTimeImmutable('2026-06-07 12:00:00'));
        self::assertFalse($missing->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $missing->failureReason);

        $ledger->credit(10, 'advertiser_balance', null, 500, 'recharge:commit-branches');
        self::assertTrue($service->reserve(10, 20, 'spend:late', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 10)->accepted);
        $expired = $service->commit('spend:late', new DateTimeImmutable('2026-06-07 12:00:11'));
        self::assertFalse($expired->accepted);
        self::assertSame(SpendFailureReason::ExpiredReservation, $expired->failureReason);
        self::assertSame(SpendReservationStatus::Expired, $expired->reservation?->status);

        self::assertTrue($service->reserve(10, 20, 'spend:released-before-commit', 100, new DateTimeImmutable('2026-06-07 12:01:00'), 60)->accepted);
        self::assertTrue($service->release('spend:released-before-commit', new DateTimeImmutable('2026-06-07 12:01:01'))->accepted);
        $released = $service->commit('spend:released-before-commit', new DateTimeImmutable('2026-06-07 12:01:02'));
        self::assertFalse($released->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $released->failureReason);

        self::assertTrue($service->reserve(10, 20, 'spend:unfunded-commit', 300, new DateTimeImmutable('2026-06-07 12:02:00'), 60)->accepted);
        $ledger->debit(10, 'advertiser_balance', null, 500, 'drain:before-commit');
        $unfunded = $service->commit('spend:unfunded-commit', new DateTimeImmutable('2026-06-07 12:02:01'));
        self::assertFalse($unfunded->accepted);
        self::assertSame(SpendFailureReason::InsufficientBalance, $unfunded->failureReason);
    }

    public function testReleaseAndExpireRejectMissingAndInvalidLifecycleStates(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $missingRelease = $service->release('spend:missing-release', new DateTimeImmutable('2026-06-07 12:00:00'));
        self::assertFalse($missingRelease->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $missingRelease->failureReason);

        $missingExpire = $service->expire('spend:missing-expire', new DateTimeImmutable('2026-06-07 12:00:00'));
        self::assertFalse($missingExpire->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $missingExpire->failureReason);

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:lifecycle-branches');
        self::assertTrue($service->reserve(10, 20, 'spend:release-idempotent', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);
        self::assertTrue($service->release('spend:release-idempotent', new DateTimeImmutable('2026-06-07 12:00:01'))->accepted);
        self::assertTrue($service->release('spend:release-idempotent', new DateTimeImmutable('2026-06-07 12:00:02'))->accepted);

        self::assertTrue($service->reserve(10, 20, 'spend:expire-idempotent', 100, new DateTimeImmutable('2026-06-07 12:01:00'), 10)->accepted);
        self::assertTrue($service->expire('spend:expire-idempotent', new DateTimeImmutable('2026-06-07 12:01:11'))->accepted);
        self::assertTrue($service->expire('spend:expire-idempotent', new DateTimeImmutable('2026-06-07 12:01:12'))->accepted);

        self::assertTrue($service->reserve(10, 20, 'spend:commit-before-expire', 100, new DateTimeImmutable('2026-06-07 12:02:00'), 60)->accepted);
        self::assertTrue($service->commit('spend:commit-before-expire', new DateTimeImmutable('2026-06-07 12:02:01'))->accepted);
        $committedExpire = $service->expire('spend:commit-before-expire', new DateTimeImmutable('2026-06-07 12:02:02'));
        self::assertFalse($committedExpire->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $committedExpire->failureReason);

        self::assertTrue($service->reserve(10, 20, 'spend:early-expire', 100, new DateTimeImmutable('2026-06-07 12:03:00'), 60)->accepted);
        $earlyExpire = $service->expire('spend:early-expire', new DateTimeImmutable('2026-06-07 12:03:30'));
        self::assertFalse($earlyExpire->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $earlyExpire->failureReason);
    }

    public function testReservationLifecyclePersistenceUsesReservedStateCas(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:reservation-cas');
        self::assertTrue($service->reserve(10, 20, 'spend:cas', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);
        $released = $budgetRepository->markReleased('spend:cas', new DateTimeImmutable('2026-06-07 12:00:01'));
        self::assertTrue($released->changed);
        self::assertSame(SpendReservationStatus::Released, $released->reservation?->status);

        $committedAfterRelease = $budgetRepository->markCommitted('spend:cas', 1, new DateTimeImmutable('2026-06-07 12:00:02'));
        $expiredAfterRelease = $budgetRepository->markExpired('spend:cas', new DateTimeImmutable('2026-06-07 12:01:01'));

        self::assertFalse($committedAfterRelease->changed);
        self::assertSame(SpendReservationStatus::Released, $committedAfterRelease->reservation?->status);
        self::assertNull($committedAfterRelease->reservation?->ledgerEntryId);
        self::assertFalse($expiredAfterRelease->changed);
        self::assertSame(SpendReservationStatus::Released, $expiredAfterRelease->reservation?->status);
        self::assertNull($expiredAfterRelease->reservation?->expiredAt);
    }

    public function testTerminalBudgetExhaustionPausesActiveCampaigns(): void
    {
        $connection = $this->createConnection(withCampaigns: true);
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService(
            $budgetRepository,
            $ledger,
            $ledgerRepository,
            new \VertoAD\Repository\Campaign\CampaignRepository($connection),
        );

        $this->insertCampaign($connection, 10, 20);
        $ledger->credit(10, 'advertiser_balance', null, 300, 'recharge:auto-pause-total-cap');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, 300, null, null));

        self::assertTrue($service->reserve(10, 20, 'spend:auto-pause-total-cap', 300, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);
        self::assertTrue($service->commit('spend:auto-pause-total-cap', new DateTimeImmutable('2026-06-07 12:00:01'))->accepted);

        self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = 20'));
        self::assertSame('total_cap_exhausted', $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = 20'));
    }

    public function testInsufficientBalanceRejectionPausesActiveCampaigns(): void
    {
        $connection = $this->createConnection(withCampaigns: true);
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService(
            $budgetRepository,
            $ledger,
            $ledgerRepository,
            new \VertoAD\Repository\Campaign\CampaignRepository($connection),
        );

        $this->insertCampaign($connection, 10, 20);
        $ledger->credit(10, 'advertiser_balance', null, 50, 'recharge:auto-pause-insufficient-balance');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, null, null, null));

        $rejected = $service->reserve(10, 20, 'spend:auto-pause-insufficient-balance', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60);

        self::assertFalse($rejected->accepted);
        self::assertSame(SpendFailureReason::InsufficientBalance, $rejected->failureReason);
        self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = 20'));
        self::assertSame('insufficient_balance', $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = 20'));
    }

    public function testDryRunBudgetRejectionPausesActiveCampaignsForCapsAndBalanceFailures(): void
    {
        $connection = $this->createConnection(withCampaigns: true);
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService(
            $budgetRepository,
            $ledger,
            $ledgerRepository,
            new \VertoAD\Repository\Campaign\CampaignRepository($connection),
        );

        $ledger->credit(10, 'advertiser_balance', null, 10_000, 'recharge:dry-run-auto-pause');
        $cases = [
            [21, new CampaignBudgetCaps(21, 10, null, null, 50), 51, SpendFailureReason::HourlyCap, 'hourly_cap'],
            [22, new CampaignBudgetCaps(22, 10, null, 50, null), 51, SpendFailureReason::DailyCap, 'daily_cap'],
            [23, new CampaignBudgetCaps(23, 10, 50, null, null), 51, SpendFailureReason::TotalCap, 'total_cap'],
        ];

        foreach ($cases as [$campaignId, $caps, $pointsAmount, $expectedReason, $expectedPauseReason]) {
            $this->insertCampaign($connection, 10, $campaignId);
            $budgetRepository->saveCaps($caps);

            self::assertSame(
                $expectedReason,
                $service->rejectionReason(10, $campaignId, $pointsAmount, new DateTimeImmutable('2026-06-07 12:00:00')),
            );
            self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = ?', [$campaignId]));
            self::assertSame($expectedPauseReason, $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = ?', [$campaignId]));
        }

        $this->insertCampaign($connection, 11, 24);
        self::assertSame(
            SpendFailureReason::InsufficientBalance,
            $service->rejectionReason(11, 24, 1, new DateTimeImmutable('2026-06-07 12:00:00')),
        );
        self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = 24'));
        self::assertSame('insufficient_balance', $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = 24'));
    }

    public function testServingBudgetPrecheckPausesCampaignWhenAdvertiserBalanceIsInsufficient(): void
    {
        $connection = $this->createConnection(withCampaigns: true);
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $budgetService = new CampaignBudgetService(
            $budgetRepository,
            $ledger,
            $ledgerRepository,
            new \VertoAD\Repository\Campaign\CampaignRepository($connection),
        );

        $this->insertCampaign($connection, 10, 20);
        $ledger->credit(10, 'advertiser_balance', null, 50, 'recharge:serving-budget-precheck');

        $serving = new \VertoAD\Service\Serving\AdServingService(
            new \VertoAD\Repository\Serving\StaticServingInventoryRepository([[1, 2]]),
            new \VertoAD\Repository\Serving\StaticAdCandidateRepository([
                new \VertoAD\Domain\Serving\AdCandidate(
                    adId: 'ad-budget-rejected',
                    campaignId: 20,
                    advertiserOrganizationId: 10,
                    creativeHtml: '<strong>VertoAD</strong>',
                    landingUrl: 'https://advertiser.example/landing',
                    width: 300,
                    height: 250,
                    impressionCostPoints: 100,
                    clickCostPoints: 100,
                ),
            ]),
            new \VertoAD\Repository\Serving\InMemoryAdDecisionRepository(),
            new \VertoAD\Repository\Serving\InMemoryAdEventRepository(),
            $budgetService,
        );

        $decision = $serving->serve(1, 2, 'viewer-budget-precheck', null, false, new DateTimeImmutable('2026-06-07 12:00:00'));

        self::assertFalse($decision->filled);
        self::assertSame('budget_insufficient_balance', $decision->reason);
        self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = 20'));
        self::assertSame('insufficient_balance', $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = 20'));
    }

    public function testCommitPausesActiveCampaignWhenAdvertiserBalanceIsExactlyExhausted(): void
    {
        $connection = $this->createConnection(withCampaigns: true);
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService(
            $budgetRepository,
            $ledger,
            $ledgerRepository,
            new \VertoAD\Repository\Campaign\CampaignRepository($connection),
        );

        $this->insertCampaign($connection, 10, 20);
        $ledger->credit(10, 'advertiser_balance', null, 100, 'recharge:auto-pause-balance-exhausted');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, null, null, null));

        self::assertTrue($service->reserve(10, 20, 'spend:auto-pause-balance-exhausted', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);
        self::assertTrue($service->commit('spend:auto-pause-balance-exhausted', new DateTimeImmutable('2026-06-07 12:00:01'))->accepted);

        self::assertSame('paused', $connection->fetchOne('SELECT status FROM campaigns WHERE id = 20'));
        self::assertSame('balance_exhausted', $connection->fetchOne('SELECT pause_reason FROM campaigns WHERE id = 20'));
    }

    public function testCommitCasFailureRollsBackAdvertiserDebit(): void
    {
        $connection = $this->createConnection();
        $innerBudgetRepository = new CampaignBudgetRepository($connection);
        $budgetRepository = new class($innerBudgetRepository, $connection) implements CampaignBudgetRepositoryInterface {
            public function __construct(
                private readonly CampaignBudgetRepository $inner,
                private readonly Connection $connection,
            ) {
            }

            public function transactional(callable $operation): mixed
            {
                return $this->inner->transactional($operation);
            }

            public function saveCaps(CampaignBudgetCaps $caps): CampaignBudgetCaps
            {
                return $this->inner->saveCaps($caps);
            }

            public function findCaps(int $organizationId, int $campaignId): ?CampaignBudgetCaps
            {
                return $this->inner->findCaps($organizationId, $campaignId);
            }

            public function findReservation(string $reservationId): ?SpendReservation
            {
                return $this->inner->findReservation($reservationId);
            }

            public function lockBudgetScope(int $organizationId, int $campaignId): void
            {
                $this->inner->lockBudgetScope($organizationId, $campaignId);
            }

            public function createReservation(SpendReservation $reservation): SpendReservation
            {
                return $this->inner->createReservation($reservation);
            }

            public function markCommitted(string $reservationId, int $ledgerEntryId, DateTimeImmutable $committedAt): SpendReservationTransition
            {
                $this->connection->update(
                    'spend_reservations',
                    ['status' => SpendReservationStatus::Released->value, 'released_at' => $committedAt->format('Y-m-d H:i:s')],
                    ['reservation_id' => trim($reservationId), 'status' => SpendReservationStatus::Reserved->value],
                );

                return $this->inner->markCommitted($reservationId, $ledgerEntryId, $committedAt);
            }

            public function markReleased(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationTransition
            {
                return $this->inner->markReleased($reservationId, $releasedAt);
            }

            public function markExpired(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationTransition
            {
                return $this->inner->markExpired($reservationId, $expiredAt);
            }

            public function activeReservedSpendForCampaign(int $organizationId, int $campaignId, DateTimeImmutable $at): int
            {
                return $this->inner->activeReservedSpendForCampaign($organizationId, $campaignId, $at);
            }

            public function committedSpendForCampaign(int $organizationId, int $campaignId): int
            {
                return $this->inner->committedSpendForCampaign($organizationId, $campaignId);
            }

            public function activeReservedSpendForCampaignWindow(
                int $organizationId,
                int $campaignId,
                DateTimeImmutable $windowStart,
                DateTimeImmutable $windowEnd,
                DateTimeImmutable $at,
            ): int {
                return $this->inner->activeReservedSpendForCampaignWindow($organizationId, $campaignId, $windowStart, $windowEnd, $at);
            }

            public function committedSpendForCampaignWindow(
                int $organizationId,
                int $campaignId,
                DateTimeImmutable $windowStart,
                DateTimeImmutable $windowEnd,
            ): int {
                return $this->inner->committedSpendForCampaignWindow($organizationId, $campaignId, $windowStart, $windowEnd);
            }

            public function activeReservedSpendForOrganization(int $organizationId, DateTimeImmutable $at): int
            {
                return $this->inner->activeReservedSpendForOrganization($organizationId, $at);
            }
        };
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 1_000, 'recharge:cas-rollback');
        self::assertTrue($service->reserve(10, 20, 'spend:cas-rollback', 100, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);

        $committed = $service->commit('spend:cas-rollback', new DateTimeImmutable('2026-06-07 12:00:01'));

        self::assertFalse($committed->accepted);
        self::assertSame(SpendFailureReason::DuplicateState, $committed->failureReason);
        self::assertSame(SpendReservationStatus::Reserved->value, $connection->fetchOne("SELECT status FROM spend_reservations WHERE reservation_id = 'spend:cas-rollback'"));
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(10));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entries WHERE idempotency_key = 'spend_reservation:spend:cas-rollback:commit'"));
    }

    public function testCreateReservationReturnsExistingRowAfterConcurrentDuplicateInsert(): void
    {
        $connection = $this->createConnection();
        $repository = new CampaignBudgetRepository($connection);
        $reservation = new SpendReservation(
            id: null,
            reservationId: 'spend:duplicate-create',
            organizationId: 10,
            campaignId: 20,
            pointsAmount: 100,
            status: SpendReservationStatus::Reserved,
            reservedAt: new DateTimeImmutable('2026-06-07 12:00:00'),
            expiresAt: new DateTimeImmutable('2026-06-07 12:01:00'),
            committedAt: null,
            releasedAt: null,
            expiredAt: null,
            ledgerEntryId: null,
        );

        $created = $repository->createReservation($reservation);
        $duplicate = $repository->createReservation($reservation);

        self::assertSame($created->id, $duplicate->id);
        self::assertSame('spend:duplicate-create', $duplicate->reservationId);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM spend_reservations WHERE reservation_id = 'spend:duplicate-create'"));
    }

    public function testMysqlBudgetScopeLockUsesUpsertsAndForUpdateLocks(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createSchemaManager', 'getDatabasePlatform', 'fetchOne', 'executeStatement'])
            ->getMock();
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->expects(self::exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, false, 10, 20);
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'ON DUPLICATE KEY UPDATE')),
                self::isArray(),
            )
            ->willReturn(1);

        (new CampaignBudgetRepository($connection))->lockBudgetScope(10, 20);
    }

    public function testBudgetLockTableDetectionFallsBackWhenSchemaInspectionFails(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willThrowException(new \RuntimeException('schema unavailable'));
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createSchemaManager', 'fetchOne', 'executeStatement'])
            ->getMock();
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeStatement');

        (new CampaignBudgetRepository($connection))->lockBudgetScope(10, 20);
    }

    public function testReserveRejectsInsufficientAdvertiserBalanceAfterOpenReservations(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 300, 'recharge:balance');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, null, null, null));

        self::assertTrue($service->reserve(10, 20, 'balance:r1', 250, new DateTimeImmutable('2026-06-07 12:00:00'), 60)->accepted);

        $rejected = $service->reserve(10, 20, 'balance:r2', 51, new DateTimeImmutable('2026-06-07 12:00:01'), 60);

        self::assertFalse($rejected->accepted);
        self::assertSame(SpendFailureReason::InsufficientBalance, $rejected->failureReason);
    }

    private function createConnection(bool $withCampaigns = false): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        if ($withCampaigns) {
            $connection->executeStatement(
                <<<'SQL'
CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY,
    organization_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    status VARCHAR(32) NOT NULL,
    pause_reason VARCHAR(160) NULL,
    pricing_model VARCHAR(16) NOT NULL,
    bid_points INTEGER NOT NULL,
    landing_url VARCHAR(1024) NOT NULL,
    creative_asset_id INTEGER NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    targeting_json TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
            );
        }
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ledger_account_balances (
    organization_id INTEGER NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    balance_points INTEGER NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, account_type)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    account_id INTEGER NULL,
    points_amount INTEGER NOT NULL,
    direction VARCHAR(16) NOT NULL,
    balance_after_points INTEGER NULL,
    reference_type VARCHAR(120) NULL,
    reference_id INTEGER NULL,
    idempotency_key VARCHAR(160) NOT NULL UNIQUE,
    memo VARCHAR(255) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE organization_budget_locks (
    organization_id INTEGER PRIMARY KEY,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE campaign_budget_locks (
    organization_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, campaign_id)
)
SQL
        );
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

        return $connection;
    }

    private function insertCampaign(Connection $connection, int $organizationId, int $campaignId): void
    {
        $connection->insert('campaigns', [
            'id' => $campaignId,
            'organization_id' => $organizationId,
            'name' => 'Budgeted campaign',
            'status' => 'active',
            'pricing_model' => 'cpc',
            'bid_points' => 100,
            'landing_url' => 'https://landing.example',
            'creative_asset_id' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'targeting_json' => '{"devices":[],"geos":[],"site_ids":[],"slot_ids":[],"time_windows":[]}',
        ]);
    }

    /**
     * @param callable(): SpendReservation $factory
     */
    private function assertInvalidReservation(callable $factory, string $message): void
    {
        try {
            $factory();
            self::fail('Expected invalid spend reservation.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertInvalidReservationOperation(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected invalid reservation operation.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
