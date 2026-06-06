<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\PointsLedgerService;

final class PointsLedgerServiceTest extends TestCase
{
    public function testCreditAppendsIntegerPointEntryWithDeterministicMetadata(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);

        $entry = $service->credit(
            organizationId: 10,
            accountType: ' advertiser_balance ',
            accountId: 20,
            pointsAmount: 100,
            idempotencyKey: ' recharge:key:abc ',
            referenceType: 'recharge_key',
            referenceId: 30,
            memo: ' 100 points equals 1 CNY ',
            metadata: [
                'z' => 'last',
                'a' => [
                    'second' => 2,
                    'first' => 1,
                ],
            ],
        );

        self::assertSame(1, $entry->id);
        self::assertSame(10, $entry->organizationId);
        self::assertSame('advertiser_balance', $entry->accountType);
        self::assertSame(20, $entry->accountId);
        self::assertSame(100, $entry->pointsAmount);
        self::assertSame(LedgerDirection::Credit, $entry->direction);
        self::assertSame('recharge:key:abc', $entry->idempotencyKey);
        self::assertSame('100 points equals 1 CNY', $entry->memo);
        self::assertSame(['a' => ['first' => 1, 'second' => 2], 'z' => 'last'], $entry->metadata);
        self::assertCount(1, $repository->entries);
    }

    public function testIdempotencyKeyReturnsExistingEntryWithoutAppendingAgain(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);

        $first = $service->debit(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: 20,
            pointsAmount: 55,
            idempotencyKey: 'billing:event:1',
        );

        $second = $service->debit(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: 20,
            pointsAmount: 99,
            idempotencyKey: 'billing:event:1',
        );

        self::assertSame($first, $second);
        self::assertSame(55, $second->pointsAmount);
        self::assertCount(1, $repository->entries);
    }

    public function testReverseCreatesOppositeDirectionEntryForOriginalLedgerEntry(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);

        $original = $service->debit(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: 20,
            pointsAmount: 250,
            idempotencyKey: 'click:charge:1',
            referenceType: 'raw_event',
            referenceId: 99,
            memo: 'Valid click charge',
        );

        $reversal = $service->reverse(
            originalEntryId: (int) $original->id,
            idempotencyKey: 'click:charge:1:reversal',
            reason: 'fraud review cleared as invalid',
            actorUserId: 7,
        );

        self::assertSame(10, $reversal->organizationId);
        self::assertSame('advertiser_balance', $reversal->accountType);
        self::assertSame(20, $reversal->accountId);
        self::assertSame(250, $reversal->pointsAmount);
        self::assertSame(LedgerDirection::Credit, $reversal->direction);
        self::assertSame('ledger_entry', $reversal->referenceType);
        self::assertSame($original->id, $reversal->referenceId);
        self::assertSame('Reversal: fraud review cleared as invalid', $reversal->memo);
        self::assertSame(
            [
                'actor_user_id' => 7,
                'entry_kind' => 'reversal',
                'reason' => 'fraud review cleared as invalid',
                'reverses_ledger_entry_id' => $original->id,
            ],
            $reversal->metadata,
        );
        self::assertCount(2, $repository->entries);
    }

    public function testAdjustmentRequiresReasonAndCreatesManualAdjustmentMetadata(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);

        $entry = $service->adjust(
            organizationId: 10,
            accountType: 'publisher_earnings',
            accountId: 33,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            idempotencyKey: 'manual:adjustment:1',
            reason: 'publisher revenue correction',
            actorUserId: 8,
        );

        self::assertSame(LedgerDirection::Credit, $entry->direction);
        self::assertSame('manual_adjustment', $entry->referenceType);
        self::assertNull($entry->referenceId);
        self::assertSame('Adjustment: publisher revenue correction', $entry->memo);
        self::assertSame(
            [
                'actor_user_id' => 8,
                'entry_kind' => 'adjustment',
                'reason' => 'publisher revenue correction',
            ],
            $entry->metadata,
        );
    }

    public function testReverseRejectsBlankReason(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);
        $original = $service->credit(10, 'advertiser_balance', null, 100, 'original');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger reversal reason is required.');

        $service->reverse((int) $original->id, 'original:reverse', ' ');
    }

    public function testAdjustmentRejectsBlankReason(): void
    {
        $service = new PointsLedgerService(new FakePointsLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger adjustment reason is required.');

        $service->adjust(
            organizationId: 10,
            accountType: 'publisher_earnings',
            accountId: 33,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            idempotencyKey: 'manual:adjustment:1',
            reason: ' ',
        );
    }

    public function testRejectsZeroPointsBeforeAppending(): void
    {
        $repository = new FakePointsLedgerRepository();
        $service = new PointsLedgerService($repository);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger points amount must be positive.');

        $service->credit(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: 20,
            pointsAmount: 0,
            idempotencyKey: 'bad',
        );
    }

    public function testRejectsBlankIdempotencyKeyBeforeAppending(): void
    {
        $service = new PointsLedgerService(new FakePointsLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger idempotency key is required.');

        $service->debit(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            idempotencyKey: ' ',
        );
    }

    public function testReverseRejectsMissingOriginalEntry(): void
    {
        $service = new PointsLedgerService(new FakePointsLedgerRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Original ledger entry was not found.');

        $service->reverse(
            originalEntryId: 999,
            idempotencyKey: 'missing:reversal',
            reason: 'missing',
        );
    }
}

final class FakePointsLedgerRepository implements PointsLedgerRepositoryInterface
{
    /** @var list<PointsLedgerEntry> */
    public array $entries = [];

    public function append(PointsLedgerEntry $entry): PointsLedgerEntry
    {
        $stored = new PointsLedgerEntry(
            id: count($this->entries) + 1,
            organizationId: $entry->organizationId,
            accountType: $entry->accountType,
            accountId: $entry->accountId,
            pointsAmount: $entry->pointsAmount,
            direction: $entry->direction,
            balanceAfterPoints: $entry->balanceAfterPoints,
            referenceType: $entry->referenceType,
            referenceId: $entry->referenceId,
            idempotencyKey: $entry->idempotencyKey,
            memo: $entry->memo,
            metadata: $entry->metadata,
        );

        $this->entries[] = $stored;

        return $stored;
    }

    public function findById(int $id): ?PointsLedgerEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?PointsLedgerEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->idempotencyKey === trim($idempotencyKey)) {
                return $entry;
            }
        }

        return null;
    }
}
