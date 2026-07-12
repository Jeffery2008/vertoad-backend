<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingStream;
use VertoAD\Domain\Billing\CpmRevenueShareSnapshot;
use VertoAD\Domain\Billing\RevenueShareRule;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Repository\Billing\DatabaseCpmBillingRepository;
use VertoAD\Repository\Billing\InMemoryCpmBillingRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\ServingInventoryRepositoryInterface;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\Serving\AdServingService;

final class CpmBillingDefensiveCoverageTest extends TestCase
{
    public function testAccumulatorRejectsInvalidStateAndArithmeticOverflow(): void
    {
        $stream = $this->stream();
        $cases = [
            [fn () => new CpmBillingAccumulator(id: 0, stream: $stream), 'CPM billing accumulator ID must be positive when provided.'],
            [fn () => new CpmBillingAccumulator(id: null, stream: $stream, grossRemainderMilliPoints: 1_000), 'CPM gross remainder must be between 0 and 999.'],
            [fn () => new CpmBillingAccumulator(id: null, stream: $stream, publisherShareRemainderNumerator: 10_000_000), 'CPM publisher share remainder numerator is invalid.'],
            [fn () => new CpmBillingAccumulator(id: null, stream: $stream, impressionCount: -1), 'CPM accumulator counters cannot be negative.'],
            [fn () => new CpmBillingAccumulator(id: null, stream: $stream, billedPoints: 0, publisherPoints: 1), 'CPM publisher points cannot exceed billed points.'],
            [fn () => (new CpmBillingAccumulator(null, $stream))->allocate(0, 1), 'CPM bid points per thousand must be positive.'],
            [fn () => (new CpmBillingAccumulator(null, $stream, grossRemainderMilliPoints: 1))->allocate(PHP_INT_MAX, 0), 'CPM bid points exceed the supported integer range.'],
            [fn () => (new CpmBillingAccumulator(null, $stream))->allocate(1, 10_001), 'CPM publisher share ratio must be between 0 and 10000 basis points.'],
            [fn () => (new CpmBillingAccumulator(null, $stream, impressionCount: PHP_INT_MAX))->allocate(1, 0), 'CPM impression accumulator has reached its integer limit.'],
            [
                fn () => (new CpmBillingAccumulator(null, $stream))->allocate(intdiv(PHP_INT_MAX, 10_000) + 1, 10_000),
                'CPM publisher share numerator exceeds the supported integer range.',
            ],
            [
                fn () => (new CpmBillingAccumulator(null, $stream, billedPoints: PHP_INT_MAX))->allocate(1_000, 0),
                'CPM billed points accumulator has reached its integer limit.',
            ],
            [
                fn () => (new CpmBillingAccumulator(
                    null,
                    $stream,
                    publisherShareRemainderNumerator: 9_999_999,
                    billedPoints: PHP_INT_MAX,
                    publisherPoints: PHP_INT_MAX,
                ))->allocate(1, 1),
                'CPM publisher points accumulator has reached its integer limit.',
            ],
        ];

        foreach ($cases as [$operation, $message]) {
            $this->assertThrows($operation, InvalidArgumentException::class, $message, RuntimeException::class);
        }
    }

    public function testAllocationRejectsInvalidFieldsAndAccruedSettlementState(): void
    {
        $cases = [
            [['id' => 0], 'CPM allocation ID must be positive when provided.'],
            [['accumulatorId' => 0], 'CPM accumulator ID must be positive when provided.'],
            [['eventKey' => ' '], 'CPM allocation event identity is required.'],
            [['eventType' => 'click'], 'CPM allocation event type must be impression.'],
            [['bidPointsPerThousand' => 0], 'CPM allocation bid points per thousand must be positive.'],
            [['assessedGrossPoints' => -1], 'CPM gross and publisher allocation points cannot be negative.'],
            [['platformPoints' => 1], 'CPM allocation points must reconcile.'],
            [
                ['assessedGrossPoints' => 0, 'grossPoints' => 1, 'platformPoints' => 1],
                'CPM assessed points cannot be below actual points.',
            ],
            [['grossRemainderAfter' => 1_000], 'CPM allocation gross remainders are invalid.'],
            [['publisherShareRemainderAfter' => 10_000_000], 'CPM allocation publisher remainders are invalid.'],
            [['advertiserLedgerEntryId' => 0], 'CPM advertiser ledger entry ID must be positive when provided.'],
            [['publisherLedgerEntryId' => 0], 'CPM publisher ledger entry ID must be positive when provided.'],
            [
                [
                    'status' => CpmBillingAllocationStatus::Accrued,
                    'reason' => 'cpm_fraction_accumulated',
                    'advertiserLedgerEntryId' => 1,
                    'processedAt' => new DateTimeImmutable('2026-07-12 12:00:01+00:00'),
                ],
                'Accrued CPM allocation cannot contain an integer settlement.',
            ],
        ];

        foreach ($cases as [$overrides, $message]) {
            $this->assertThrows(
                fn () => $this->allocation($overrides),
                InvalidArgumentException::class,
                $message,
            );
        }
    }

    public function testStreamAndRuleSnapshotRejectInvalidInputsAndSupportRuleWithoutId(): void
    {
        $cases = [
            [fn () => new CpmBillingStream(0, 123, 42, 5, 10), 'CPM billing stream identifiers must be positive.'],
            [fn () => CpmBillingStream::fromRule(99, 123, 42, 5, 10, 0, 5_000), 'CPM billing revenue share rule ID must be positive when provided.'],
            [fn () => CpmBillingStream::fromRule(99, 123, 42, 5, 10, 1, 10_001), 'CPM billing share ratio must be between 0 and 10000 basis points.'],
            [fn () => new CpmRevenueShareSnapshot(0, 'id:0', 5_000), 'CPM revenue share rule ID must be positive when provided.'],
            [fn () => new CpmRevenueShareSnapshot(1, ' ', 5_000), 'CPM revenue share rule key is required.'],
            [fn () => new CpmRevenueShareSnapshot(1, 'id:1', 10_001), 'CPM revenue share ratio must be between 0 and 10000 basis points.'],
        ];

        foreach ($cases as [$operation, $message]) {
            $this->assertThrows($operation, InvalidArgumentException::class, $message);
        }

        $snapshot = CpmRevenueShareSnapshot::fromRule(new RevenueShareRule(
            id: null,
            scope: 'global',
            organizationId: null,
            siteId: null,
            adSlotId: null,
            shareRatioBps: 5_000,
            status: 'active',
            version: 7,
            createdByUserId: null,
            createdAt: new DateTimeImmutable('2026-07-12 12:00:00+00:00'),
        ));
        self::assertSame('version:7:global:5000', $snapshot->ruleKey);
    }

    public function testInMemoryRepositoryRejectsInvalidTransitionsAndVersionConflicts(): void
    {
        $stream = $this->stream();

        $this->assertThrows(
            fn () => (new InMemoryCpmBillingRepository())->saveAccumulator(new CpmBillingAccumulator(1, $stream, version: PHP_INT_MAX)),
            RuntimeException::class,
            'CPM accumulator version has reached its integer limit.',
        );
        $this->assertThrows(
            fn () => (new InMemoryCpmBillingRepository())->saveAccumulator(new CpmBillingAccumulator(1, $stream)),
            RuntimeException::class,
            'CPM accumulator does not exist.',
        );

        $repository = new InMemoryCpmBillingRepository();
        $stale = $repository->lockAccumulator($stream);
        $repository->saveAccumulator($stale);
        $this->assertThrows(
            fn () => $repository->saveAccumulator($stale),
            RuntimeException::class,
            'CPM accumulator version conflict.',
        );
        $this->assertThrows(
            fn () => $repository->claimAllocation($this->skippedAllocation(1, 'invalid-claim')),
            RuntimeException::class,
            'CPM allocation claim must have processing status.',
        );
        $this->assertThrows(
            fn () => $repository->completeAllocation($this->processingAllocation()),
            RuntimeException::class,
            'CPM allocation completion must have a final status.',
        );
        $this->assertThrows(
            fn () => $repository->completeAllocation($this->skippedAllocation(1, 'missing-claim')),
            RuntimeException::class,
            'CPM allocation claim is not available for completion.',
        );
    }

    public function testDatabaseRepositoryRejectsInvalidTransitionsAndFallbacksAreCovered(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseCpmBillingRepository($connection);
        $stream = $this->stream();

        self::assertNull($repository->findAllocation(' '));
        $this->assertThrows(
            fn () => $repository->saveAccumulator(new CpmBillingAccumulator(1, $stream, version: PHP_INT_MAX)),
            RuntimeException::class,
            'CPM accumulator version has reached its integer limit.',
        );

        $stale = $repository->lockAccumulator($stream);
        $repository->saveAccumulator($stale);
        $this->assertThrows(
            fn () => $repository->saveAccumulator($stale),
            RuntimeException::class,
            'CPM accumulator version conflict.',
        );
        $this->assertThrows(
            fn () => $repository->claimAllocation($this->skippedAllocation(1, 'invalid-claim')),
            RuntimeException::class,
            'CPM allocation claim must have processing status.',
        );
        $this->assertThrows(
            fn () => $repository->completeAllocation($this->processingAllocation()),
            RuntimeException::class,
            'CPM allocation completion must have a final status.',
        );
        $this->assertThrows(
            fn () => $repository->completeAllocation($this->skippedAllocation(null, 'missing-id')),
            RuntimeException::class,
            'CPM allocation claim ID is required for completion.',
        );
        $this->assertThrows(
            fn () => $repository->completeAllocation($this->skippedAllocation(999, 'missing-claim')),
            RuntimeException::class,
            'CPM allocation claim is not available for completion.',
        );

        $missingAccumulatorConnection = $this->connection(DefensiveCpmConnection::class);
        self::assertInstanceOf(DefensiveCpmConnection::class, $missingAccumulatorConnection);
        $missingAccumulatorConnection->hideAccumulatorFetch = true;
        $this->assertThrows(
            fn () => (new DatabaseCpmBillingRepository($missingAccumulatorConnection))->lockAccumulator($stream),
            RuntimeException::class,
            'CPM accumulator row could not be loaded after creation.',
        );

        $uniqueConnection = $this->connection(DefensiveCpmConnection::class);
        self::assertInstanceOf(DefensiveCpmConnection::class, $uniqueConnection);
        $uniqueConnection->failAllocationInsert = true;
        $uniqueConnection->hideAllocationFetch = true;
        $this->expectException(UniqueConstraintViolationException::class);
        try {
            (new DatabaseCpmBillingRepository($uniqueConnection))->claimAllocation($this->processingAllocation('unique-failure'));
        } finally {
            $fallbackConnection = $this->connection(DefensiveCpmConnection::class);
            self::assertInstanceOf(DefensiveCpmConnection::class, $fallbackConnection);
            $fallbackConnection->hideAllocationFetch = true;
            $claim = (new DatabaseCpmBillingRepository($fallbackConnection))->claimAllocation(
                $this->processingAllocation('fallback-id'),
            );
            self::assertTrue($claim->acquired);
            self::assertNotNull($claim->allocation->id);
        }
    }

    public function testCpmPublisherCreditRejectsNegativeDeltasAndMissingRuleLookupIsFailClosed(): void
    {
        $connection = $this->connection();
        $service = new RevenueShareService(
            new RevenueShareRepository($connection),
            new PointsLedgerService(new PointsLedgerRepository($connection)),
        );
        $rule = new RevenueShareRule(
            id: 1,
            scope: 'global',
            organizationId: null,
            siteId: null,
            adSlotId: null,
            shareRatioBps: 5_000,
            status: 'active',
            version: 1,
            createdByUserId: null,
            createdAt: new DateTimeImmutable('2026-07-12 12:00:00+00:00'),
        );

        self::assertSame(
            'missing_revenue_share_rule',
            $service->rejectionReasonForAdEvent('missing-rule', 42, 5, 10, 1),
        );
        $this->assertThrows(
            fn () => $service->creditForCpmAllocation('', 42, 5, 10, 99, 123, 0, 0, $rule, new DateTimeImmutable()),
            InvalidArgumentException::class,
            'Ad event id is required for publisher earnings.',
        );
        $this->assertThrows(
            fn () => $service->creditForCpmAllocation('negative-gross', 42, 5, 10, 99, 123, -1, 0, $rule, new DateTimeImmutable()),
            InvalidArgumentException::class,
            'CPM gross points cannot be negative.',
        );
        $this->assertThrows(
            fn () => $service->creditForCpmAllocation('negative-publisher', 42, 5, 10, 99, 123, 0, -1, $rule, new DateTimeImmutable()),
            InvalidArgumentException::class,
            'CPM publisher points cannot be negative.',
        );
    }

    public function testServingFailsClosedWhenVerifiedSlotHasNoPublisherOwner(): void
    {
        $service = new AdServingService(
            new MissingPublisherInventoryRepository(),
            new StaticAdCandidateRepository([
                new AdCandidate(
                    adId: 'missing-publisher-cpm',
                    campaignId: 123,
                    advertiserOrganizationId: 99,
                    creativeHtml: '<strong>CPM</strong>',
                    landingUrl: 'https://advertiser.example/cpm',
                    width: 300,
                    height: 250,
                    impressionCostPoints: 999,
                    clickCostPoints: 0,
                ),
            ]),
            new InMemoryAdDecisionRepository(),
            new InMemoryAdEventRepository(),
        );

        $decision = $service->serve(5, 10, 'missing-publisher', null, false, new DateTimeImmutable());

        self::assertFalse($decision->filled);
        self::assertSame('missing_publisher_organization', $decision->reason);
    }

    /** @param array<string, mixed> $overrides */
    private function allocation(array $overrides = []): CpmBillingAllocation
    {
        $values = array_replace([
            'id' => null,
            'eventKey' => hash('sha256', 'defensive-allocation'),
            'eventType' => 'impression',
            'eventId' => 'defensive-allocation',
            'decisionId' => 'decision-defensive-allocation',
            'stream' => $this->stream(),
            'revenueShare' => CpmRevenueShareSnapshot::missing(),
            'accumulatorId' => null,
            'bidPointsPerThousand' => 1,
            'assessedGrossPoints' => 0,
            'status' => CpmBillingAllocationStatus::Processing,
            'reason' => null,
            'grossRemainderBefore' => 0,
            'grossPoints' => 0,
            'grossRemainderAfter' => 0,
            'publisherShareRemainderBefore' => 0,
            'publisherPoints' => 0,
            'publisherShareRemainderAfter' => 0,
            'platformPoints' => 0,
            'advertiserLedgerEntryId' => null,
            'publisherLedgerEntryId' => null,
            'reservationId' => null,
            'occurredAt' => new DateTimeImmutable('2026-07-12 12:00:00+00:00'),
            'processedAt' => null,
        ], $overrides);

        return new CpmBillingAllocation(...$values);
    }

    private function processingAllocation(string $suffix = 'processing'): CpmBillingAllocation
    {
        return $this->allocation([
            'eventKey' => hash('sha256', 'defensive-' . $suffix),
            'eventId' => 'defensive-' . $suffix,
            'decisionId' => 'decision-defensive-' . $suffix,
        ]);
    }

    private function skippedAllocation(?int $id, string $suffix): CpmBillingAllocation
    {
        return $this->allocation([
            'id' => $id,
            'eventKey' => hash('sha256', 'defensive-' . $suffix),
            'eventId' => 'defensive-' . $suffix,
            'decisionId' => 'decision-defensive-' . $suffix,
            'status' => CpmBillingAllocationStatus::Skipped,
            'reason' => $suffix,
            'processedAt' => new DateTimeImmutable('2026-07-12 12:00:01+00:00'),
        ]);
    }

    /** @param class-string<Connection> $wrapper */
    private function connection(string $wrapper = Connection::class): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($wrapper !== Connection::class) {
            $params['wrapperClass'] = $wrapper;
        }
        $connection = DriverManager::getConnection($params);
        BillingTask14Schema::create($connection);

        return $connection;
    }

    private function stream(): CpmBillingStream
    {
        return new CpmBillingStream(99, 123, 42, 5, 10);
    }

    /**
     * @param callable(): mixed $operation
     * @param class-string<\Throwable> $expectedClass
     * @param class-string<\Throwable>|null $alternateClass
     */
    private function assertThrows(
        callable $operation,
        string $expectedClass,
        string $message,
        ?string $alternateClass = null,
    ): void {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertTrue(
                $exception instanceof $expectedClass || ($alternateClass !== null && $exception instanceof $alternateClass),
                'Unexpected exception type: ' . $exception::class,
            );
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('Expected exception: ' . $message);
    }
}

final class DefensiveCpmConnection extends Connection
{
    public bool $hideAccumulatorFetch = false;
    public bool $hideAllocationFetch = false;
    public bool $failAllocationInsert = false;

    /** @param array<string, mixed> $data @param array<int|string, mixed> $types */
    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'cpm_billing_event_allocations' && $this->failAllocationInsert) {
            $driverException = new class('duplicate CPM allocation') extends RuntimeException implements DriverException {
                public function getSQLState(): ?string
                {
                    return '23000';
                }
            };

            throw new UniqueConstraintViolationException($driverException, null);
        }

        return parent::insert($table, $data, $types);
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, mixed>|array<string, mixed> $types
     */
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if ($this->hideAccumulatorFetch && str_contains($query, 'cpm_billing_accumulators')) {
            return false;
        }
        if ($this->hideAllocationFetch && str_contains($query, 'cpm_billing_event_allocations')) {
            return false;
        }

        return parent::fetchAssociative($query, $params, $types);
    }
}

final readonly class MissingPublisherInventoryRepository implements ServingInventoryRepositoryInterface
{
    public function isVerifiedActiveSlot(int $siteId, int $slotId): bool
    {
        return true;
    }

    public function publisherOrganizationIdForSlot(int $siteId, int $slotId): ?int
    {
        return null;
    }
}
