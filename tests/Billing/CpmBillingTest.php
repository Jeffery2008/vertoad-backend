<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingClaim;
use VertoAD\Domain\Billing\CpmBillingStream;
use VertoAD\Domain\Billing\CpmRevenueShareSnapshot;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Domain\Budget\SpendReservationTransition;
use VertoAD\Repository\Billing\DatabaseCpmBillingRepository;
use VertoAD\Repository\Billing\CpmBillingRepositoryInterface;
use VertoAD\Repository\Billing\InMemoryCpmBillingRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\CpmBillingService;
use VertoAD\Service\Billing\CpmBillingUnavailableException;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class CpmBillingTest extends TestCase
{
    public function testStreamIdentityIsStableAcrossRevenueShareRuleSnapshots(): void
    {
        $oldRuleStream = CpmBillingStream::fromRule(99, 123, 42, 5, 10, 1, 5_000);
        $newRuleStream = CpmBillingStream::fromRule(99, 123, 42, 5, 10, 2, 8_000);

        self::assertSame($oldRuleStream->key(), $newRuleStream->key());
    }

    public function testAccumulatesIntegerRemainderInsteadOfCeilingEveryImpression(): void
    {
        [$connection, $ledgerRepository, $service, $cpmRepository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );

        $first = $service->bill($this->impression('imp-1', 333));
        $second = $service->bill($this->impression('imp-2', 333));
        $third = $service->bill($this->impression('imp-3', 333));
        $fourth = $service->bill($this->impression('imp-4', 333));
        $replay = $service->bill($this->impression('imp-4', 333));

        self::assertTrue($first->billed);
        self::assertSame('cpm_fraction_accumulated', $first->reason);
        self::assertSame(0, $first->grossPoints);
        self::assertSame(0, $second->grossPoints);
        self::assertSame(0, $third->grossPoints);
        self::assertSame(1, $fourth->grossPoints);
        self::assertSame(0, $fourth->publisherPoints);
        self::assertTrue($replay->duplicate);
        self::assertSame(9, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $cpmRepository);
        $allocations = $cpmRepository->allocations();
        self::assertCount(4, $allocations);
        self::assertSame(CpmBillingAllocationStatus::Billed, $allocations[3]->status);
        self::assertSame(999, $allocations[3]->grossRemainderBefore);
        self::assertSame(332, $allocations[3]->grossRemainderAfter);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entries WHERE direction = 'debit'"));
    }

    public function testPublisherShareRemainderEventuallyCreditsExactIntegerShare(): void
    {
        [, $ledgerRepository, $service, $cpmRepository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );

        $first = $service->bill($this->impression('share-1', 1_000));
        $second = $service->bill($this->impression('share-2', 1_000));

        self::assertSame(1, $first->grossPoints);
        self::assertSame(0, $first->publisherPoints);
        self::assertSame(1, $second->grossPoints);
        self::assertSame(1, $second->publisherPoints);
        self::assertSame(8, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(1, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $allocations = $cpmRepository->allocations();
        self::assertSame(6_000_000, $allocations[0]->publisherShareRemainderAfter);
        self::assertSame(2_000_000, $allocations[1]->publisherShareRemainderAfter);
        self::assertSame(1, $allocations[0]->platformPoints);
        self::assertSame(0, $allocations[1]->platformPoints);
    }

    public function testRuleSwitchPreservesGrossAndWeightsPublisherEntitlementByEachEventRule(): void
    {
        [$connection, $ledgerRepository, $service, $repository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );
        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $repository);
        $rules = new RevenueShareRepository($connection);
        $oldRule = $rules->findBestRule(42, 5, 10);
        self::assertNotNull($oldRule);

        $oldRuleEvent = $service->bill($this->impression('rule-old-999', 999));
        $newRule = $rules->createRule(
            'global',
            null,
            null,
            null,
            5_000,
            null,
            new DateTimeImmutable('2026-07-11 09:30:00'),
        );
        $grossCrossing = $service->bill($this->impression('rule-new-1', 1));
        $publisherOnly = $service->bill($this->impression('rule-new-801', 801));

        self::assertSame(0, $oldRuleEvent->grossPoints);
        self::assertSame(1, $grossCrossing->grossPoints);
        self::assertSame(0, $grossCrossing->publisherPoints);
        self::assertSame(0, $publisherOnly->grossPoints);
        self::assertSame(1, $publisherOnly->publisherPoints);
        self::assertSame(9, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(1, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $allocations = $repository->allocations();
        self::assertCount(3, $allocations);
        self::assertSame($oldRule->id, $allocations[0]->revenueShare->ruleId);
        self::assertSame($newRule->id, $allocations[1]->revenueShare->ruleId);
        self::assertSame($newRule->id, $allocations[2]->revenueShare->ruleId);
        self::assertSame(5_994_000, $allocations[0]->publisherShareRemainderAfter);
        self::assertSame(5_999_000, $allocations[1]->publisherShareRemainderAfter);
        self::assertSame(4_000, $allocations[2]->publisherShareRemainderAfter);
        self::assertSame(1, $allocations[1]->platformPoints);
        self::assertSame(-1, $allocations[2]->platformPoints);
        self::assertNull($allocations[2]->advertiserLedgerEntryId);
        self::assertNull($allocations[2]->reservationId);
        self::assertNotNull($allocations[2]->publisherLedgerEntryId);

        $accumulator = $repository->accumulatorFor(new CpmBillingStream(99, 123, 42, 5, 10));
        self::assertNotNull($accumulator);
        self::assertSame(3, $accumulator->impressionCount);
        self::assertSame(1, $accumulator->billedPoints);
        self::assertSame(801, $accumulator->grossRemainderMilliPoints);
        self::assertSame(1, $accumulator->publisherPoints);
        self::assertSame(4_000, $accumulator->publisherShareRemainderNumerator);
        self::assertSame(0, $accumulator->billedPoints - $accumulator->publisherPoints);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_earning_events'));
    }

    public function testFrequentRuleSwitchesCannotSuppressGrossBillingOnMemoryOrDatabasePath(): void
    {
        foreach ([true, false] as $inMemoryCpm) {
            [$connection, $ledgerRepository, $service, $repository] = $this->service(
                bidBalance: 100,
                shareRatioBps: 5_000,
                inMemoryCpm: $inMemoryCpm,
            );
            $rules = new RevenueShareRepository($connection);
            $grossPoints = 0;
            $publisherPoints = 0;

            for ($number = 0; $number < 20; ++$number) {
                if ($number > 0) {
                    $rules->createRule(
                        'global',
                        null,
                        null,
                        null,
                        $number % 2 === 0 ? 5_000 : 6_000,
                        null,
                        new DateTimeImmutable('2026-07-11 09:' . str_pad((string) $number, 2, '0', STR_PAD_LEFT) . ':00'),
                    );
                }
                $result = $service->bill($this->impression('switch-' . $number, 999));
                $grossPoints += $result->grossPoints;
                $publisherPoints += $result->publisherPoints;
            }

            $stream = new CpmBillingStream(99, 123, 42, 5, 10);
            $accumulator = $repository->findAccumulator($stream);
            self::assertNotNull($accumulator);
            self::assertSame(20, $accumulator->impressionCount);
            self::assertSame(19, $grossPoints);
            self::assertSame(19, $accumulator->billedPoints);
            self::assertSame(980, $accumulator->grossRemainderMilliPoints);
            self::assertSame(10, $publisherPoints);
            self::assertSame(10, $accumulator->publisherPoints);
            self::assertSame(9_890_000, $accumulator->publisherShareRemainderNumerator);
            self::assertSame(9, $accumulator->billedPoints - $accumulator->publisherPoints);
            self::assertSame(81, $ledgerRepository->balanceForOrganization(99));
            self::assertSame(10, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        }
    }

    public function testBudgetRejectionDoesNotAdvanceEitherRemainderAcrossRuleSwitch(): void
    {
        [$connection, $ledgerRepository, $service, $repository] = $this->service(
            bidBalance: 0,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );
        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $repository);

        $service->bill($this->impression('budget-remainder-old', 999));
        (new RevenueShareRepository($connection))->createRule(
            'global',
            null,
            null,
            null,
            5_000,
            null,
            new DateTimeImmutable('2026-07-11 09:30:00'),
        );
        $rejectedEvent = $this->impression('budget-remainder-rejected', 1);
        $rejected = $service->bill($rejectedEvent);
        $replay = $service->bill($rejectedEvent);

        $stream = new CpmBillingStream(99, 123, 42, 5, 10);
        $beforeRecharge = $repository->accumulatorFor($stream);
        self::assertNotNull($beforeRecharge);
        self::assertSame('insufficient_balance', $rejected->reason);
        self::assertTrue($replay->duplicate);
        self::assertSame(999, $beforeRecharge->grossRemainderMilliPoints);
        self::assertSame(5_994_000, $beforeRecharge->publisherShareRemainderNumerator);
        self::assertSame(1, $beforeRecharge->impressionCount);

        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 10, 'recharge:weighted-remainder');
        $accepted = $service->bill($this->impression('budget-remainder-accepted', 1));
        $afterRecharge = $repository->accumulatorFor($stream);
        self::assertNotNull($afterRecharge);
        self::assertSame(1, $accepted->grossPoints);
        self::assertSame(0, $afterRecharge->grossRemainderMilliPoints);
        self::assertSame(5_999_000, $afterRecharge->publisherShareRemainderNumerator);
        self::assertSame(2, $afterRecharge->impressionCount);
        self::assertSame(9, $ledgerRepository->balanceForOrganization(99));
    }

    public function testServingPreflightEstimateUsesThePersistedGrossRemainder(): void
    {
        [, , $service, , $cpm] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );

        self::assertSame(0, $cpm->nextChargePoints(99, 123, 42, 5, 10, 333));
        $service->bill($this->impression('estimate-1', 333));
        $service->bill($this->impression('estimate-2', 333));
        $service->bill($this->impression('estimate-3', 333));

        self::assertSame(1, $cpm->nextChargePoints(99, 123, 42, 5, 10, 333));
    }

    public function testInsufficientBalanceDoesNotAdvanceAndReplayRemainsSkippedAfterRecharge(): void
    {
        [$connection, $ledgerRepository, $service, $cpmRepository] = $this->service(
            bidBalance: 0,
            shareRatioBps: 5_000,
            inMemoryCpm: true,
        );
        $event = $this->impression('insufficient-1', 1_000);

        $first = $service->bill($event);
        (new PointsLedgerService($ledgerRepository))->credit(99, 'advertiser_balance', null, 10, 'recharge:after-skip');
        $replay = $service->bill($event);
        $next = $service->bill($this->impression('insufficient-2', 1_000));

        self::assertFalse($first->billed);
        self::assertSame('insufficient_balance', $first->reason);
        self::assertFalse($replay->billed);
        self::assertTrue($replay->duplicate);
        self::assertSame('insufficient_balance', $replay->reason);
        self::assertTrue($next->billed);
        self::assertSame(1, $next->grossPoints);
        self::assertSame(9, $ledgerRepository->balanceForOrganization(99));

        $allocations = $cpmRepository->allocations();
        self::assertSame(CpmBillingAllocationStatus::Skipped, $allocations[0]->status);
        self::assertSame(1, $allocations[0]->assessedGrossPoints);
        self::assertSame(0, $allocations[0]->grossRemainderAfter);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entries WHERE direction = 'debit'"));
    }

    public function testPublisherStreamsDoNotShareGrossRemainder(): void
    {
        [$connection, $ledgerRepository, $service, $cpmRepository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 5_000,
            inMemoryCpm: true,
        );
        $connection->insert('sites', [
            'id' => 6,
            'organization_id' => 43,
            'name' => 'Publisher Two',
            'domain' => 'publisher-two.example',
            'status' => 'verified',
        ]);
        $connection->insert('ad_slots', [
            'id' => 11,
            'site_id' => 6,
            'name' => 'Second',
            'slot_key' => 'second',
            'width' => 300,
            'height' => 250,
            'status' => 'active',
        ]);

        $first = $service->bill($this->impression('publisher-a', 600));
        $second = $service->bill($this->impression(
            'publisher-b',
            600,
            siteId: 6,
            slotId: 11,
            publisherOrganizationId: 43,
        ));

        self::assertSame(0, $first->grossPoints);
        self::assertSame(0, $second->grossPoints);
        self::assertSame(10, $ledgerRepository->balanceForOrganization(99));
        self::assertCount(2, $cpmRepository->allocations());
        self::assertNotSame(
            $cpmRepository->allocations()[0]->stream->key(),
            $cpmRepository->allocations()[1]->stream->key(),
        );
    }

    public function testConflictingReplayIsRejected(): void
    {
        [, , $service] = $this->service(bidBalance: 10, shareRatioBps: 5_000, inMemoryCpm: true);
        $service->bill($this->impression('conflict', 1_000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CPM billing event replay conflicts with the original allocation.');

        $service->bill($this->impression('conflict', 2_000));
    }

    public function testLengthPrefixedEventIdentityPreventsDelimiterAmbiguity(): void
    {
        [, , $service, $repository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 5_000,
            inMemoryCpm: true,
        );

        $first = $service->bill($this->impression('b|c', 333, decisionId: 'a'));
        $second = $service->bill($this->impression('c', 333, decisionId: 'a|b'));

        self::assertTrue($first->billed);
        self::assertTrue($second->billed);
        self::assertFalse($second->duplicate);
        self::assertCount(2, $repository->allocations());
        self::assertNotSame($repository->allocations()[0]->eventKey, $repository->allocations()[1]->eventKey);
    }

    public function testReplayWithChangedValidityIsRejectedBeforeTheInvalidEventShortcut(): void
    {
        [, , $service] = $this->service(bidBalance: 10, shareRatioBps: 5_000, inMemoryCpm: true);
        $service->bill($this->impression('validity-conflict', 1_000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CPM billing event replay conflicts with the original allocation.');

        $service->bill($this->impression('validity-conflict', 1_000, valid: false));
    }

    public function testRejectsNonImpressionsAndSkipsNewInvalidOrZeroCostEvents(): void
    {
        [, , , , $cpm] = $this->service(bidBalance: 10, shareRatioBps: 5_000, inMemoryCpm: true);

        $invalid = $cpm->bill($this->impression('invalid-new', 1_000, valid: false));
        $zero = $cpm->bill($this->impression('zero-new', 0));

        self::assertFalse($invalid->billed);
        self::assertSame('invalid_event', $invalid->reason);
        self::assertFalse($zero->billed);
        self::assertSame('zero_cost', $zero->reason);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CPM billing only accepts impression events.');

        $cpm->bill(new BillableAdEvent(
            eventType: 'click',
            eventId: 'wrong-type',
            decisionId: 'decision-wrong-type',
            siteId: 5,
            slotId: 10,
            publisherOrganizationId: 42,
            advertiserOrganizationId: 99,
            campaignId: 123,
            adId: 'ad-1',
            viewerId: 'viewer-1',
            costPoints: 10,
            valid: true,
            occurredAt: new DateTimeImmutable('2026-07-11 10:00:00'),
        ));
    }

    public function testEstimatorFailsClosedWhenRevenueShareSettlementIsUnavailable(): void
    {
        [, , , , $cpm] = $this->service(
            bidBalance: 10,
            shareRatioBps: 5_000,
            inMemoryCpm: true,
            createRule: false,
        );

        self::assertSame(0, $cpm->nextChargePoints(99, 123, 42, 5, 10, 0));
        $this->assertCpmUnavailable(
            static fn (): int => $cpm->nextChargePoints(99, 123, 42, 5, 10, 999),
            'missing_revenue_share_rule',
        );

        [, , , , $zeroShareCpm] = $this->service(
            bidBalance: 10,
            shareRatioBps: 0,
            inMemoryCpm: true,
        );
        $this->assertCpmUnavailable(
            static fn (): int => $zeroShareCpm->nextChargePoints(99, 123, 42, 5, 10, 1_000),
            'zero_publisher_earning',
        );
    }

    public function testMissingAndZeroShareRulesProduceDurableSkippedReplays(): void
    {
        [, , $missingRuleService, $missingRuleRepository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 5_000,
            inMemoryCpm: true,
            createRule: false,
        );
        $missing = $this->impression('missing-rule', 1_000);
        $missingFirst = $missingRuleService->bill($missing);
        $missingReplay = $missingRuleService->bill($missing);

        [, , $zeroShareService, $zeroShareRepository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 0,
            inMemoryCpm: true,
        );
        $zeroShare = $this->impression('zero-share', 1_000);
        $zeroFirst = $zeroShareService->bill($zeroShare);
        $zeroReplay = $zeroShareService->bill($zeroShare);

        self::assertSame('missing_revenue_share_rule', $missingFirst->reason);
        self::assertTrue($missingReplay->duplicate);
        self::assertSame('missing_revenue_share_rule', $missingReplay->reason);
        self::assertCount(1, $missingRuleRepository->allocations());
        self::assertSame(CpmBillingAllocationStatus::Skipped, $missingRuleRepository->allocations()[0]->status);

        self::assertSame('zero_publisher_earning', $zeroFirst->reason);
        self::assertTrue($zeroReplay->duplicate);
        self::assertSame('zero_publisher_earning', $zeroReplay->reason);
        self::assertCount(1, $zeroShareRepository->allocations());
        self::assertSame(CpmBillingAllocationStatus::Skipped, $zeroShareRepository->allocations()[0]->status);
    }

    public function testInMemoryTransactionRollsBackEventClaimAndAccumulatorTogether(): void
    {
        $repository = new InMemoryCpmBillingRepository();
        $stream = new CpmBillingStream(99, 123, 42, 5, 10);
        $claim = $this->processingAllocation('rollback-event', $stream, 333);

        try {
            $repository->transactional(function () use ($repository, $stream, $claim): void {
                self::assertTrue($repository->claimAllocation($claim)->acquired);
                $accumulator = $repository->lockAccumulator($stream);
                $repository->saveAccumulator($accumulator->allocate(333, 6_000)->nextAccumulator);

                throw new \RuntimeException('force rollback');
            });
            self::fail('Expected the in-memory CPM transaction to roll back.');
        } catch (\RuntimeException $exception) {
            self::assertSame('force rollback', $exception->getMessage());
        }

        self::assertSame([], $repository->allocations());
        self::assertNull($repository->accumulatorFor($stream));
    }

    public function testClaimWonByAnotherWorkerReturnsItsFinalResultWithoutTouchingState(): void
    {
        [, $ledgerRepository, , $repository, , $budgets, $revenueShare] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );
        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $repository);

        $event = $this->impression('claim-race', 333);
        $stream = new CpmBillingStream(99, 123, 42, 5, 10);
        $eventKey = $this->billingEventKey($event->decisionId, $event->eventId);
        $processing = $this->processingAllocation(
            $event->eventId,
            $stream,
            333,
            eventKey: $eventKey,
            decisionId: $event->decisionId,
        );
        $repository->transactional(function () use ($repository, $processing): void {
            $claim = $repository->claimAllocation($processing);
            $repository->completeAllocation($this->skippedAllocation($claim->allocation, 'already_finalized'));
        });

        $service = new CpmBillingService(
            new HideFirstAllocationCpmRepository($repository),
            $budgets,
            $revenueShare,
        );
        $result = $service->bill($event);

        self::assertFalse($result->billed);
        self::assertTrue($result->duplicate);
        self::assertSame('already_finalized', $result->reason);
        self::assertSame(10, $ledgerRepository->balanceForOrganization(99));
        self::assertNull($repository->accumulatorFor($stream));
    }

    public function testConflictingClaimWonByAnotherWorkerIsRejectedBeforeStateMutation(): void
    {
        [, $ledgerRepository, , $repository, , $budgets, $revenueShare] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );
        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $repository);

        $event = $this->impression('claim-conflict', 333);
        $conflictingStream = new CpmBillingStream(99, 124, 42, 5, 10);
        $processing = $this->processingAllocation(
            $event->eventId,
            $conflictingStream,
            333,
            eventKey: $this->billingEventKey($event->decisionId, $event->eventId),
            decisionId: $event->decisionId,
        );
        $repository->transactional(function () use ($repository, $processing): void {
            $claim = $repository->claimAllocation($processing);
            $repository->completeAllocation($this->skippedAllocation($claim->allocation, 'other_worker'));
        });

        $service = new CpmBillingService(
            new HideFirstAllocationCpmRepository($repository),
            $budgets,
            $revenueShare,
        );

        try {
            $service->bill($event);
            self::fail('Expected a conflicting concurrent CPM claim to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'CPM billing event replay conflicts with the original allocation.',
                $exception->getMessage(),
            );
        }

        self::assertSame(10, $ledgerRepository->balanceForOrganization(99));
        self::assertNull($repository->accumulatorFor($conflictingStream));
        self::assertCount(1, $repository->allocations());
    }

    public function testCommitRejectionFinalizesSkipWithoutAdvancingRemainders(): void
    {
        [$connection, $ledgerRepository, , $repository, , , $revenueShare] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: true,
        );
        self::assertInstanceOf(InMemoryCpmBillingRepository::class, $repository);
        $ledger = new PointsLedgerService($ledgerRepository);
        $service = new CpmBillingService(
            $repository,
            new CampaignBudgetService(new CommitRejectingCpmBudgetRepository(), $ledger, $ledgerRepository),
            $revenueShare,
        );

        $result = $service->bill($this->impression('commit-rejected', 1_000));
        $stream = new CpmBillingStream(99, 123, 42, 5, 10);
        $accumulator = $repository->accumulatorFor($stream);

        self::assertFalse($result->billed);
        self::assertSame('duplicate_state', $result->reason);
        self::assertNotNull($accumulator);
        self::assertSame(0, $accumulator->impressionCount);
        self::assertSame(0, $accumulator->grossRemainderMilliPoints);
        self::assertSame(10, $ledgerRepository->balanceForOrganization(99));
        self::assertSame(0, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entries WHERE direction = 'debit'"));
        self::assertSame(CpmBillingAllocationStatus::Skipped, $repository->allocations()[0]->status);
        self::assertSame(1, $repository->allocations()[0]->assessedGrossPoints);
    }

    public function testMySqlPathClaimsCompletesAndLocksDuplicateEvent(): void
    {
        $connection = $this->createConnection(MySqlLikeCpmConnection::class);
        self::assertInstanceOf(MySqlLikeCpmConnection::class, $connection);
        $repository = new DatabaseCpmBillingRepository($connection);
        $stream = new CpmBillingStream(99, 123, 42, 5, 10);
        $processing = $this->processingAllocation('mysql-claim', $stream, 333);

        [$completed, $duplicate] = $repository->transactional(function () use ($repository, $processing): array {
            $claim = $repository->claimAllocation($processing);
            self::assertTrue($claim->acquired);

            $completed = $repository->completeAllocation($this->skippedAllocation(
                $claim->allocation,
                'test_finalized',
            ));
            $duplicate = $repository->claimAllocation($processing);

            return [$completed, $duplicate];
        });

        self::assertSame(CpmBillingAllocationStatus::Skipped, $completed->status);
        self::assertFalse($duplicate->acquired);
        self::assertSame($completed->id, $duplicate->allocation->id);
        self::assertSame(CpmBillingAllocationStatus::Skipped, $duplicate->allocation->status);
        self::assertTrue($connection->sawAllocationForUpdate);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM cpm_billing_event_allocations'));
    }

    public function testAllocationLifecycleRejectsStatesThatMySqlChecksWouldReject(): void
    {
        $this->assertInvalidAllocation(
            fn (): CpmBillingAllocation => $this->lifecycleAllocation(
                status: CpmBillingAllocationStatus::Processing,
                assessedGrossPoints: 1,
                grossPoints: 1,
                platformPoints: 1,
            ),
            'Processing CPM allocation must not contain settlement state.',
        );
        $this->assertInvalidAllocation(
            fn (): CpmBillingAllocation => $this->lifecycleAllocation(
                status: CpmBillingAllocationStatus::Accrued,
                reason: 'cpm_fraction_accumulated',
            ),
            'Final CPM allocation must have a processing timestamp.',
        );
        $this->assertInvalidAllocation(
            fn (): CpmBillingAllocation => $this->lifecycleAllocation(
                status: CpmBillingAllocationStatus::Billed,
                assessedGrossPoints: 1,
                grossPoints: 1,
                platformPoints: 1,
                reservationId: 'reservation-1',
                processedAt: new DateTimeImmutable('2026-07-11 10:00:01'),
            ),
            'Billed CPM allocation settlement state is invalid.',
        );
        $this->assertInvalidAllocation(
            fn (): CpmBillingAllocation => $this->lifecycleAllocation(
                status: CpmBillingAllocationStatus::Skipped,
                reason: 'insufficient_balance',
                grossRemainderAfter: 1,
                processedAt: new DateTimeImmutable('2026-07-11 10:00:01'),
            ),
            'Skipped CPM allocation must not mutate settlement state.',
        );
    }

    public function testDatabaseServicePathPersistsFractionalStateAndStableReplay(): void
    {
        [$connection, $ledgerRepository, $service, $repository] = $this->service(
            bidBalance: 10,
            shareRatioBps: 6_000,
            inMemoryCpm: false,
        );
        self::assertInstanceOf(DatabaseCpmBillingRepository::class, $repository);

        $event = $this->impression('mysql-service', 333);
        $first = $service->bill($event);
        $replay = $service->bill($event);

        self::assertTrue($first->billed);
        self::assertSame('cpm_fraction_accumulated', $first->reason);
        self::assertTrue($replay->duplicate);
        self::assertSame(333, (int) $connection->fetchOne('SELECT gross_remainder_milli_points FROM cpm_billing_accumulators'));
        self::assertSame('accrued', (string) $connection->fetchOne('SELECT status FROM cpm_billing_event_allocations'));
        self::assertSame(10, $ledgerRepository->balanceForOrganization(99));
    }

    public function testDatabaseRepositoryUsesMySqlUpsertAndRowLockPath(): void
    {
        $connection = $this->createConnection(MySqlLikeCpmConnection::class);
        self::assertInstanceOf(MySqlLikeCpmConnection::class, $connection);
        $repository = new DatabaseCpmBillingRepository($connection);
        $stream = new CpmBillingStream(99, 123, 42, 5, 10);

        $saved = $repository->transactional(function () use ($repository, $stream) {
            $accumulator = $repository->lockAccumulator($stream);
            return $repository->saveAccumulator($accumulator->allocate(333, 6_000)->nextAccumulator);
        });

        self::assertTrue($connection->sawAccumulatorUpsert);
        self::assertTrue($connection->sawAccumulatorForUpdate);
        self::assertSame(333, $saved->grossRemainderMilliPoints);
        self::assertSame(1, $saved->impressionCount);
        self::assertSame(1, $saved->version);
    }

    /**
     * @return array{Connection, PointsLedgerRepository, AdEventBillingService, InMemoryCpmBillingRepository|DatabaseCpmBillingRepository, CpmBillingService, CampaignBudgetService, RevenueShareService}
     */
    private function service(
        int $bidBalance,
        int $shareRatioBps,
        bool $inMemoryCpm,
        bool $createRule = true,
        string $connectionClass = Connection::class,
    ): array
    {
        $connection = $this->createConnection($connectionClass);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        if ($bidBalance > 0) {
            $ledger->credit(99, 'advertiser_balance', null, $bidBalance, 'recharge:initial');
        }

        $revenueRepository = new RevenueShareRepository($connection);
        if ($createRule) {
            $revenueRepository->createRule('global', null, null, null, $shareRatioBps, null, new DateTimeImmutable('2026-07-11 09:00:00'));
        }
        $budgets = new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository);
        $revenueShare = new RevenueShareService($revenueRepository, $ledger);
        $cpmRepository = $inMemoryCpm
            ? new InMemoryCpmBillingRepository()
            : new DatabaseCpmBillingRepository($connection);
        $cpm = new CpmBillingService($cpmRepository, $budgets, $revenueShare);

        return [
            $connection,
            $ledgerRepository,
            new AdEventBillingService($budgets, $revenueShare, $connection, $cpm),
            $cpmRepository,
            $cpm,
            $budgets,
            $revenueShare,
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function createConnection(string $connectionClass = Connection::class): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($connectionClass !== Connection::class) {
            $params['wrapperClass'] = $connectionClass;
        }
        $connection = DriverManager::getConnection($params);
        BillingTask14Schema::create($connection);
        $connection->executeStatement(
            'CREATE TABLE campaign_budget_caps (
                campaign_id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                total_cap_points INTEGER NULL,
                daily_cap_points INTEGER NULL,
                hourly_cap_points INTEGER NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE spend_reservations (
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
            )',
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

    private function impression(
        string $eventId,
        int $bidPointsPerThousand,
        int $siteId = 5,
        int $slotId = 10,
        int $publisherOrganizationId = 42,
        bool $valid = true,
        ?DateTimeImmutable $occurredAt = null,
        ?string $decisionId = null,
    ): BillableAdEvent {
        return new BillableAdEvent(
            eventType: 'impression',
            eventId: $eventId,
            decisionId: $decisionId ?? 'decision-' . $eventId,
            siteId: $siteId,
            slotId: $slotId,
            publisherOrganizationId: $publisherOrganizationId,
            advertiserOrganizationId: 99,
            campaignId: 123,
            adId: 'ad-1',
            viewerId: 'viewer-1',
            costPoints: $bidPointsPerThousand,
            valid: $valid,
            occurredAt: $occurredAt ?? new DateTimeImmutable('2026-07-11 10:00:00'),
        );
    }

    private function processingAllocation(
        string $eventId,
        CpmBillingStream $stream,
        int $bidPointsPerThousand,
        ?string $eventKey = null,
        ?string $decisionId = null,
    ): CpmBillingAllocation {
        return new CpmBillingAllocation(
            id: null,
            eventKey: $eventKey ?? hash('sha256', 'test|' . $eventId),
            eventType: 'impression',
            eventId: $eventId,
            decisionId: $decisionId ?? 'decision-' . $eventId,
            stream: $stream,
            revenueShare: new CpmRevenueShareSnapshot(1, 'id:1', 6_000),
            accumulatorId: null,
            bidPointsPerThousand: $bidPointsPerThousand,
            assessedGrossPoints: 0,
            status: CpmBillingAllocationStatus::Processing,
            reason: null,
            grossRemainderBefore: 0,
            grossPoints: 0,
            grossRemainderAfter: 0,
            publisherShareRemainderBefore: 0,
            publisherPoints: 0,
            publisherShareRemainderAfter: 0,
            platformPoints: 0,
            advertiserLedgerEntryId: null,
            publisherLedgerEntryId: null,
            reservationId: null,
            occurredAt: new DateTimeImmutable('2026-07-11 10:00:00'),
            processedAt: null,
        );
    }

    private function skippedAllocation(CpmBillingAllocation $claim, string $reason): CpmBillingAllocation
    {
        return new CpmBillingAllocation(
            id: $claim->id,
            eventKey: $claim->eventKey,
            eventType: $claim->eventType,
            eventId: $claim->eventId,
            decisionId: $claim->decisionId,
            stream: $claim->stream,
            revenueShare: $claim->revenueShare,
            accumulatorId: null,
            bidPointsPerThousand: $claim->bidPointsPerThousand,
            assessedGrossPoints: 0,
            status: CpmBillingAllocationStatus::Skipped,
            reason: $reason,
            grossRemainderBefore: 0,
            grossPoints: 0,
            grossRemainderAfter: 0,
            publisherShareRemainderBefore: 0,
            publisherPoints: 0,
            publisherShareRemainderAfter: 0,
            platformPoints: 0,
            advertiserLedgerEntryId: null,
            publisherLedgerEntryId: null,
            reservationId: null,
            occurredAt: $claim->occurredAt,
            processedAt: new DateTimeImmutable('2026-07-11 10:00:01'),
        );
    }

    private function lifecycleAllocation(
        CpmBillingAllocationStatus $status,
        ?string $reason = null,
        int $assessedGrossPoints = 0,
        int $grossPoints = 0,
        int $grossRemainderAfter = 0,
        int $platformPoints = 0,
        ?int $advertiserLedgerEntryId = null,
        ?string $reservationId = null,
        ?DateTimeImmutable $processedAt = null,
    ): CpmBillingAllocation {
        return new CpmBillingAllocation(
            id: 1,
            eventKey: hash('sha256', 'lifecycle-test'),
            eventType: 'impression',
            eventId: 'lifecycle-test',
            decisionId: 'decision-lifecycle-test',
            stream: new CpmBillingStream(99, 123, 42, 5, 10),
            revenueShare: new CpmRevenueShareSnapshot(1, 'id:1', 6_000),
            accumulatorId: null,
            bidPointsPerThousand: 1_000,
            assessedGrossPoints: $assessedGrossPoints,
            status: $status,
            reason: $reason,
            grossRemainderBefore: 0,
            grossPoints: $grossPoints,
            grossRemainderAfter: $grossRemainderAfter,
            publisherShareRemainderBefore: 0,
            publisherPoints: 0,
            publisherShareRemainderAfter: 0,
            platformPoints: $platformPoints,
            advertiserLedgerEntryId: $advertiserLedgerEntryId,
            publisherLedgerEntryId: null,
            reservationId: $reservationId,
            occurredAt: new DateTimeImmutable('2026-07-11 10:00:00'),
            processedAt: $processedAt,
        );
    }

    private function assertInvalidAllocation(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('Expected invalid CPM allocation state.');
    }

    private function assertCpmUnavailable(callable $operation, string $reason): void
    {
        try {
            $operation();
        } catch (CpmBillingUnavailableException $exception) {
            self::assertSame($reason, $exception->reason);

            return;
        }

        self::fail('Expected CPM billing preflight to fail closed.');
    }

    private function billingEventKey(string $decisionId, string $eventId): string
    {
        $decisionId = trim($decisionId);
        $eventId = trim($eventId);

        return hash('sha256', "cpm-v1\0"
            . pack('N', strlen($decisionId)) . $decisionId
            . pack('N', strlen($eventId)) . $eventId);
    }
}

final class MySqlLikeCpmConnection extends Connection
{
    public bool $sawAccumulatorUpsert = false;
    public bool $sawAccumulatorForUpdate = false;
    public bool $sawAllocationForUpdate = false;

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new MySQL80Platform();
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, mixed>|array<string, mixed> $types
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE updated_at = updated_at')) {
            $this->sawAccumulatorUpsert = true;
            $sql = str_replace('INSERT INTO cpm_billing_accumulators', 'INSERT OR IGNORE INTO cpm_billing_accumulators', $sql);
            $sql = str_replace(' ON DUPLICATE KEY UPDATE updated_at = updated_at', '', $sql);
        }

        return parent::executeStatement($sql, $params, $types);
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, mixed>|array<string, mixed> $types
     */
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if (str_contains($query, ' FOR UPDATE')) {
            if (str_contains($query, 'cpm_billing_accumulators')) {
                $this->sawAccumulatorForUpdate = true;
            }
            if (str_contains($query, 'cpm_billing_event_allocations')) {
                $this->sawAllocationForUpdate = true;
            }
            $query = str_replace(' FOR UPDATE', '', $query);
        }

        return parent::fetchAssociative($query, $params, $types);
    }
}

final class HideFirstAllocationCpmRepository implements CpmBillingRepositoryInterface
{
    private bool $hideNextAllocation = true;

    public function __construct(private readonly InMemoryCpmBillingRepository $inner)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->inner->transactional($operation);
    }

    public function findAllocation(string $eventKey): ?CpmBillingAllocation
    {
        if ($this->hideNextAllocation) {
            $this->hideNextAllocation = false;

            return null;
        }

        return $this->inner->findAllocation($eventKey);
    }

    public function findAccumulator(CpmBillingStream $stream): ?CpmBillingAccumulator
    {
        return $this->inner->findAccumulator($stream);
    }

    public function lockAccumulator(CpmBillingStream $stream): CpmBillingAccumulator
    {
        return $this->inner->lockAccumulator($stream);
    }

    public function saveAccumulator(CpmBillingAccumulator $accumulator): CpmBillingAccumulator
    {
        return $this->inner->saveAccumulator($accumulator);
    }

    public function claimAllocation(CpmBillingAllocation $allocation): CpmBillingClaim
    {
        return $this->inner->claimAllocation($allocation);
    }

    public function completeAllocation(CpmBillingAllocation $allocation): CpmBillingAllocation
    {
        return $this->inner->completeAllocation($allocation);
    }
}

final class CommitRejectingCpmBudgetRepository implements CampaignBudgetRepositoryInterface
{
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

    public function markCommitted(
        string $reservationId,
        int $ledgerEntryId,
        DateTimeImmutable $committedAt,
    ): SpendReservationTransition {
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
}
