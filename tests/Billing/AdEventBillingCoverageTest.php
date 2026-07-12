<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class AdEventBillingCoverageTest extends TestCase
{
    public function testSchemaInspectionFailureFallsBackToInMemoryCpmBillingInTesting(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('tablesExist')
            ->with(['cpm_billing_accumulators', 'cpm_billing_event_allocations'])
            ->willThrowException(new RuntimeException('schema unavailable'));
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createSchemaManager', 'transactional'])
            ->getMock();
        $connection->expects(self::once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager);
        $connection->expects(self::once())
            ->method('transactional')
            ->with(self::isInstanceOf(Closure::class))
            ->willReturnCallback(static fn (Closure $operation): mixed => $operation());

        $dependencyConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        [$budgets, $revenueShare] = $this->billingDependencies($dependencyConnection);
        $previousEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=testing');

        try {
            $service = new AdEventBillingService($budgets, $revenueShare, $connection);
            $result = $service->bill($this->invalidImpression());
        } finally {
            $this->restoreEnvironment($previousEnvironment);
        }

        self::assertFalse($result->billed);
        self::assertFalse($result->duplicate);
        self::assertSame('invalid_event', $result->reason);
        self::assertSame(0, $result->grossPoints);
        self::assertSame(0, $result->publisherPoints);
        self::assertSame([], $dependencyConnection->createSchemaManager()->listTableNames());
    }

    public function testMissingCpmTablesFailClosedOutsideLocalAndTesting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        [$budgets, $revenueShare] = $this->billingDependencies($connection);
        $previousEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $caught = null;

        try {
            new AdEventBillingService($budgets, $revenueShare, $connection);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->restoreEnvironment($previousEnvironment);
        }

        self::assertNotNull($caught);
        self::assertSame('CPM billing tables are required outside local/testing.', $caught->getMessage());
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
    }

    /** @return array{CampaignBudgetService, RevenueShareService} */
    private function billingDependencies(Connection $connection): array
    {
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);

        return [
            new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository),
            new RevenueShareService(new RevenueShareRepository($connection), $ledger),
        ];
    }

    private function invalidImpression(): BillableAdEvent
    {
        return new BillableAdEvent(
            eventType: 'impression',
            eventId: 'schema-fallback-invalid-impression',
            decisionId: 'schema-fallback-decision',
            siteId: 5,
            slotId: 10,
            publisherOrganizationId: 42,
            advertiserOrganizationId: 99,
            campaignId: 123,
            adId: 'ad-1',
            viewerId: 'viewer-1',
            costPoints: 1_000,
            valid: false,
            occurredAt: new DateTimeImmutable('2026-07-12 12:00:00'),
        );
    }

    private function restoreEnvironment(string|false $previousEnvironment): void
    {
        if ($previousEnvironment === false) {
            putenv('APP_ENV');
            return;
        }

        putenv('APP_ENV=' . $previousEnvironment);
    }
}
