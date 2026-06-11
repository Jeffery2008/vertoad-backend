<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\PointsLedgerService;

final class RevenueShareTest extends TestCase
{
    public function testCreditsPublisherEarningsUsingMostSpecificActiveRevenueShareRule(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $this->seedSlot($connection);

        $repository = new RevenueShareRepository($connection);
        $repository->createRule('global', null, null, null, 5000, 1, new DateTimeImmutable('2026-06-08 10:00:00'));
        $publisherRule = $repository->createRule('publisher', 42, null, null, 6500, 7, new DateTimeImmutable('2026-06-08 10:01:00'));
        $slotRule = $repository->createRule('slot', null, null, 10, 8000, 7, new DateTimeImmutable('2026-06-08 10:02:00'));

        $service = new RevenueShareService(
            $repository,
            new PointsLedgerService(new PointsLedgerRepository($connection)),
        );

        $earning = $service->creditForAdEvent(
            eventId: 'evt-1',
            publisherOrganizationId: 42,
            siteId: 5,
            adSlotId: 10,
            advertiserOrganizationId: 99,
            campaignId: 123,
            grossPoints: 1250,
            earnedAt: new DateTimeImmutable('2026-06-08 11:00:00'),
        );

        self::assertSame(1000, $earning->publisherPoints);
        self::assertSame(250, $earning->platformPoints);
        self::assertSame(8000, $earning->shareRatioBps);
        self::assertSame($slotRule->id, $earning->revenueShareRuleId);
        self::assertSame(1000, (new PointsLedgerRepository($connection))->balanceForOrganization(42, 'publisher_earnings'));

        $duplicate = $service->creditForAdEvent('evt-1', 42, 5, 10, 99, 123, 9999, new DateTimeImmutable('2026-06-08 11:01:00'));

        self::assertSame($earning->id, $duplicate->id);
        self::assertSame(1000, (new PointsLedgerRepository($connection))->balanceForOrganization(42, 'publisher_earnings'));
        self::assertGreaterThan($publisherRule->version, $slotRule->version);
    }

    public function testRevenueShareRulesAreVersionedAndValidateRatios(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new RevenueShareRepository($connection);

        $first = $repository->createRule('global', null, null, null, 5000, 1, new DateTimeImmutable('2026-06-08 10:00:00'));
        $second = $repository->createRule('global', null, null, null, 5500, 1, new DateTimeImmutable('2026-06-08 10:01:00'));

        self::assertSame(1, $first->version);
        self::assertSame(2, $second->version);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Revenue share ratio must be between 0 and 10000 basis points.');
        $repository->createRule('global', null, null, null, 10001, 1, new DateTimeImmutable('2026-06-08 10:02:00'));
    }

    public function testRevenueShareRepositoryHandlesEmptyLookupsAndInvalidScopes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new RevenueShareRepository($connection);

        self::assertNull($repository->findBestRule(42, 5, 10));
        self::assertNull($repository->findEarningByEventId('   '));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Revenue share scope is invalid.');
        $repository->createRule('invalid', null, null, null, 5000, null, new DateTimeImmutable('2026-06-08 10:00:00'));
    }

    public function testRevenueShareRulesValidateScopeTargetsBeforeWriting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new RevenueShareRepository($connection);

        foreach ([
            ['global', 42, null, null],
            ['publisher', null, null, null],
            ['site', null, null, null],
            ['slot', null, null, null],
            ['slot', null, 5, 10],
        ] as [$scope, $organizationId, $siteId, $slotId]) {
            try {
                $repository->createRule($scope, $organizationId, $siteId, $slotId, 5000, null, new DateTimeImmutable('2026-06-08 10:00:00'));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Revenue share scope target is invalid.', $exception->getMessage());
                continue;
            }

            self::fail('Expected invalid revenue share scope target for ' . $scope . '.');
        }
    }

    public function testRevenueShareServiceRejectsMissingEventIdsAndZeroEarnings(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new RevenueShareRepository($connection);
        $service = new RevenueShareService(
            $repository,
            new PointsLedgerService(new PointsLedgerRepository($connection)),
        );

        $this->assertInvalidRevenueShare(
            static fn () => $service->creditForAdEvent('', 42, 5, 10, 99, null, 100, new DateTimeImmutable('2026-06-08 11:00:00')),
            'Ad event id is required for publisher earnings.',
        );
        $repository->createRule('global', null, null, null, 0, null, new DateTimeImmutable('2026-06-08 10:00:00'));
        $this->assertInvalidRevenueShare(
            static fn () => $service->creditForAdEvent('evt-zero', 42, 5, 10, 99, null, 100, new DateTimeImmutable('2026-06-08 11:00:00')),
            'Publisher earning points must be positive.',
        );
    }

    public function testRevenueShareServiceRejectsMissingRulesBeforeCrediting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new RevenueShareRepository($connection);
        $service = new RevenueShareService(
            $repository,
            new PointsLedgerService(new PointsLedgerRepository($connection)),
        );

        $this->assertInvalidRevenueShare(
            static fn () => $service->creditForAdEvent('evt-missing-rule', 42, 5, 10, 99, null, 100, new DateTimeImmutable('2026-06-08 11:00:00')),
            'Revenue share rule is required for publisher earnings.',
        );
    }

    private function seedSlot(\Doctrine\DBAL\Connection $connection): void
    {
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
    }

    private function assertInvalidRevenueShare(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            return;
        }

        self::fail('Expected invalid revenue share operation.');
    }
}
