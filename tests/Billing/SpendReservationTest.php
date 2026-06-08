<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Repository\CampaignBudgetRepository;
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

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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
