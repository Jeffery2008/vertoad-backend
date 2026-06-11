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
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class BudgetCapTest extends TestCase
{
    public function testBudgetCapsRejectInvalidIdentifiersAndCapAmounts(): void
    {
        $this->assertInvalidBudgetCaps(
            static fn (): CampaignBudgetCaps => new CampaignBudgetCaps(0, 10, null, null, null),
            'Campaign budget campaign ID must be positive.',
        );
        $this->assertInvalidBudgetCaps(
            static fn (): CampaignBudgetCaps => new CampaignBudgetCaps(20, 0, null, null, null),
            'Campaign budget organization ID must be positive.',
        );
        $this->assertInvalidBudgetCaps(
            static fn (): CampaignBudgetCaps => new CampaignBudgetCaps(20, 10, 0, null, null),
            'Campaign total budget cap must be positive when provided.',
        );
        $this->assertInvalidBudgetCaps(
            static fn (): CampaignBudgetCaps => new CampaignBudgetCaps(20, 10, null, -1, null),
            'Campaign daily budget cap must be positive when provided.',
        );
        $this->assertInvalidBudgetCaps(
            static fn (): CampaignBudgetCaps => new CampaignBudgetCaps(20, 10, null, null, 0),
            'Campaign hourly budget cap must be positive when provided.',
        );
    }

    public function testCapsCanBeSavedUpdatedAndIgnoredForInvalidLookupKeys(): void
    {
        $connection = $this->createConnection();
        $repository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $service = new CampaignBudgetService($repository, new PointsLedgerService($ledgerRepository), $ledgerRepository);

        $saved = $service->saveCaps(new CampaignBudgetCaps(20, 10, 500, 300, 100));
        self::assertSame(500, $saved->totalCapPoints);

        $updated = $service->saveCaps(new CampaignBudgetCaps(20, 10, null, 250, null));

        self::assertNull($repository->findCaps(0, 20));
        self::assertNull($repository->findCaps(10, 0));
        self::assertNull($repository->findReservation('   '));
        self::assertEquals($updated, $repository->findCaps(10, 20));
    }

    public function testReservationsRespectTotalDailyAndHourlyPointCaps(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 10_000, 'recharge:budget-caps');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(
            campaignId: 20,
            organizationId: 10,
            totalCapPoints: 500,
            dailyCapPoints: 300,
            hourlyCapPoints: 200,
        ));

        $first = $service->reserve(10, 20, 'cap:r1', 150, new DateTimeImmutable('2026-06-07 10:15:00'), 7_200);
        self::assertTrue($first->accepted);

        $hourly = $service->reserve(10, 20, 'cap:r2', 60, new DateTimeImmutable('2026-06-07 10:30:00'), 300);
        self::assertFalse($hourly->accepted);
        self::assertSame(SpendFailureReason::HourlyCap, $hourly->failureReason);
        self::assertSame(
            SpendFailureReason::HourlyCap,
            $service->rejectionReason(10, 20, 60, new DateTimeImmutable('2026-06-07 10:30:00')),
        );

        $daily = $service->reserve(10, 20, 'cap:r3', 160, new DateTimeImmutable('2026-06-07 11:00:00'), 300);
        self::assertFalse($daily->accepted);
        self::assertSame(SpendFailureReason::DailyCap, $daily->failureReason);

        $service->commit('cap:r1', new DateTimeImmutable('2026-06-07 10:16:00'));
        $secondDay = $service->reserve(10, 20, 'cap:r4', 300, new DateTimeImmutable('2026-06-08 09:00:00'), 300);
        self::assertFalse($secondDay->accepted);
        self::assertSame(SpendFailureReason::HourlyCap, $secondDay->failureReason);

        $okSecondDay = $service->reserve(10, 20, 'cap:r5', 200, new DateTimeImmutable('2026-06-08 09:00:00'), 300);
        self::assertTrue($okSecondDay->accepted);
        $service->commit('cap:r5', new DateTimeImmutable('2026-06-08 09:01:00'));

        $total = $service->reserve(10, 20, 'cap:r6', 151, new DateTimeImmutable('2026-06-09 09:00:00'), 300);
        self::assertFalse($total->accepted);
        self::assertSame(SpendFailureReason::TotalCap, $total->failureReason);
        self::assertNull($service->rejectionReason(10, 20, 50, new DateTimeImmutable('2026-06-09 09:00:00')));
    }

    public function testDryRunSpendEligibilityChecksOpenReservationsAndAdvertiserBalance(): void
    {
        $connection = $this->createConnection();
        $budgetRepository = new CampaignBudgetRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CampaignBudgetService($budgetRepository, $ledger, $ledgerRepository);

        $ledger->credit(10, 'advertiser_balance', null, 300, 'recharge:budget-dry-run');
        $budgetRepository->saveCaps(new CampaignBudgetCaps(20, 10, null, null, null));

        self::assertNull($service->rejectionReason(10, 99, 25, new DateTimeImmutable('2026-06-07 10:14:00')));
        self::assertNull($service->rejectionReason(10, 20, 250, new DateTimeImmutable('2026-06-07 10:15:00')));
        self::assertTrue($service->reserve(10, 20, 'dry-run:r1', 250, new DateTimeImmutable('2026-06-07 10:15:00'), 300)->accepted);
        self::assertSame(
            SpendFailureReason::InsufficientBalance,
            $service->rejectionReason(10, 20, 51, new DateTimeImmutable('2026-06-07 10:16:00')),
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createLedgerTable($connection);
        $this->createBudgetTables($connection);

        return $connection;
    }

    private function createLedgerTable(Connection $connection): void
    {
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

    /**
     * @param callable(): CampaignBudgetCaps $factory
     */
    private function assertInvalidBudgetCaps(callable $factory, string $message): void
    {
        try {
            $factory();
            self::fail('Expected invalid campaign budget caps.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
