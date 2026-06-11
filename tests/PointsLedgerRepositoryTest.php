<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\PointsLedgerRepository;

final class PointsLedgerRepositoryTest extends TestCase
{
    public function testAppendPersistsCreditEntryAndFindByIdHydratesMetadataJson(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $repository = new PointsLedgerRepository($connection);

        $stored = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: 20,
            pointsAmount: 1500,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: 2500,
            referenceType: 'recharge_key',
            referenceId: 30,
            idempotencyKey: 'recharge:key:1',
            memo: 'Recharge credited',
            metadata: [
                'source' => 'manual/recharge',
                'nested' => ['first' => 1, 'second' => 2],
            ],
        ));

        self::assertSame(1, $stored->id);

        $row = $connection->fetchAssociative('SELECT * FROM ledger_entries WHERE id = ?', [$stored->id]);
        self::assertIsArray($row);
        self::assertSame(10, (int) $row['organization_id']);
        self::assertSame('advertiser_balance', $row['account_type']);
        self::assertSame(20, (int) $row['account_id']);
        self::assertSame(1500, (int) $row['points_amount']);
        self::assertSame('credit', $row['direction']);
        self::assertSame(1500, (int) $row['balance_after_points']);
        self::assertSame('recharge_key', $row['reference_type']);
        self::assertSame(30, (int) $row['reference_id']);
        self::assertSame('recharge:key:1', $row['idempotency_key']);
        self::assertSame('Recharge credited', $row['memo']);
        self::assertSame('{"source":"manual/recharge","nested":{"first":1,"second":2}}', $row['metadata_json']);

        $found = $repository->findById((int) $stored->id);

        self::assertNotNull($found);
        self::assertSame($stored->id, $found->id);
        self::assertSame(10, $found->organizationId);
        self::assertSame('advertiser_balance', $found->accountType);
        self::assertSame(20, $found->accountId);
        self::assertSame(1500, $found->pointsAmount);
        self::assertSame(LedgerDirection::Credit, $found->direction);
        self::assertSame(1500, $found->balanceAfterPoints);
        self::assertSame('recharge_key', $found->referenceType);
        self::assertSame(30, $found->referenceId);
        self::assertSame('recharge:key:1', $found->idempotencyKey);
        self::assertSame('Recharge credited', $found->memo);
        self::assertSame(
            ['source' => 'manual/recharge', 'nested' => ['first' => 1, 'second' => 2]],
            $found->metadata,
        );
    }

    public function testAppendPersistsDebitEntryWithNullableFieldsAndFindByIdempotencyKeyTrimsLookup(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $repository = new PointsLedgerRepository($connection);
        $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 11,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'publisher:seed',
            memo: null,
            metadata: null,
        ));

        $stored = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 11,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: 75,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'click:charge:1',
            memo: null,
            metadata: null,
        ));

        $row = $connection->fetchAssociative('SELECT * FROM ledger_entries WHERE id = ?', [$stored->id]);
        self::assertIsArray($row);
        self::assertSame('debit', $row['direction']);
        self::assertNull($row['account_id']);
        self::assertSame(25, (int) $row['balance_after_points']);
        self::assertNull($row['reference_type']);
        self::assertNull($row['reference_id']);
        self::assertNull($row['memo']);
        self::assertNull($row['metadata_json']);

        $found = $repository->findByIdempotencyKey('  click:charge:1  ');

        self::assertNotNull($found);
        self::assertSame($stored->id, $found->id);
        self::assertSame(11, $found->organizationId);
        self::assertSame('publisher_earnings', $found->accountType);
        self::assertNull($found->accountId);
        self::assertSame(75, $found->pointsAmount);
        self::assertSame(LedgerDirection::Debit, $found->direction);
        self::assertSame(25, $found->balanceAfterPoints);
        self::assertNull($found->referenceType);
        self::assertNull($found->referenceId);
        self::assertSame('click:charge:1', $found->idempotencyKey);
        self::assertNull($found->memo);
        self::assertNull($found->metadata);
    }

    public function testAppendRejectsDebitThatWouldOverdrawAccount(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection(withBalanceTable: true));
        $repository->append(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 100, LedgerDirection::Credit, null, null, null, 'append:overdraw:seed', null, null));

        $this->expectException(\VertoAD\Domain\Ledger\InsufficientLedgerBalanceException::class);
        $this->expectExceptionMessage('insufficient_ledger_balance');

        $repository->append(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 101, LedgerDirection::Debit, null, null, null, 'append:overdraw:debit', null, null));
    }

    public function testAppendMaintainsAccountBalanceRowsAndReturnsExistingDuplicateEntries(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $repository = new PointsLedgerRepository($connection);

        $credit = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 1_000,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: 99_999,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'balance:credit',
            memo: null,
            metadata: null,
        ));
        $debit = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 250,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'balance:debit',
            memo: null,
            metadata: null,
        ));

        $duplicateDebit = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 250,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'balance:debit',
            memo: null,
            metadata: null,
        ));

        self::assertSame(1_000, $credit->balanceAfterPoints);
        self::assertSame(750, $debit->balanceAfterPoints);
        self::assertSame($debit->id, $duplicateDebit->id);
        self::assertSame(750, $duplicateDebit->balanceAfterPoints);
        self::assertSame(750, $repository->balanceForOrganization(10));
        self::assertSame(750, (int) $connection->fetchOne(
            "SELECT balance_points FROM ledger_account_balances WHERE organization_id = 10 AND account_type = 'advertiser_balance'",
        ));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testAppendRejectsConflictingIdempotencyPayload(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection(withBalanceTable: true));
        $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 1_000,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'balance:conflict',
            memo: null,
            metadata: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ledger idempotency key conflicts with an existing entry.');

        $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 999,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'balance:conflict',
            memo: null,
            metadata: null,
        ));
    }

    public function testTryDebitAtomicallyRejectsInsufficientBalanceWithoutWritingLedgerEntry(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $repository = new PointsLedgerRepository($connection);
        $repository->append(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 100, LedgerDirection::Credit, null, null, null, 'try-debit:seed', null, null));

        $rejected = $repository->tryDebit(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 101, LedgerDirection::Debit, null, null, null, 'try-debit:too-much', null, null));
        $accepted = $repository->tryDebit(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 100, LedgerDirection::Debit, null, null, null, 'try-debit:exact', null, null));
        $duplicate = $repository->tryDebit(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 100, LedgerDirection::Debit, null, null, null, 'try-debit:exact', null, null));

        self::assertNull($rejected);
        self::assertNotNull($accepted);
        self::assertSame(0, $accepted->balanceAfterPoints);
        self::assertSame($accepted->id, $duplicate?->id);
        self::assertSame(0, $repository->balanceForOrganization(10));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testTryDebitRejectsCreditEntriesBeforeWriting(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $repository = new PointsLedgerRepository($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tryDebit requires a debit ledger entry.');

        $repository->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'try-debit:credit',
            memo: null,
            metadata: null,
        ));
    }

    public function testAppendReturnsExistingEntryCreatedInsideTransactionByConcurrentRetry(): void
    {
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: RaceInjectingLedgerConnection::class);
        assert($connection instanceof RaceInjectingLedgerConnection);
        $connection->beforeTransactional = static function (Connection $connection): void {
            $connection->insert('ledger_entries', [
                'organization_id' => 10,
                'account_type' => 'advertiser_balance',
                'account_id' => null,
                'points_amount' => 250,
                'direction' => 'credit',
                'balance_after_points' => 250,
                'reference_type' => null,
                'reference_id' => null,
                'idempotency_key' => 'append:race-second-read',
                'memo' => null,
                'metadata_json' => null,
            ]);
        };

        $stored = (new PointsLedgerRepository($connection))->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 250,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'append:race-second-read',
            memo: null,
            metadata: null,
        ));

        self::assertSame(1, $stored->id);
        self::assertSame(250, $stored->balanceAfterPoints);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testTryDebitReturnsExistingEntryCreatedInsideTransactionByConcurrentRetry(): void
    {
        $connection = $this->createConnection(connectionClass: RaceInjectingLedgerConnection::class);
        assert($connection instanceof RaceInjectingLedgerConnection);
        $connection->beforeTransactional = static function (Connection $connection): void {
            $connection->insert('ledger_entries', [
                'organization_id' => 10,
                'account_type' => 'advertiser_balance',
                'account_id' => null,
                'points_amount' => 100,
                'direction' => 'debit',
                'balance_after_points' => 900,
                'reference_type' => null,
                'reference_id' => null,
                'idempotency_key' => 'try-debit:race-second-read',
                'memo' => null,
                'metadata_json' => null,
            ]);
        };

        $stored = (new PointsLedgerRepository($connection))->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'try-debit:race-second-read',
            memo: null,
            metadata: null,
        ));

        self::assertSame(1, $stored?->id);
        self::assertSame(900, $stored?->balanceAfterPoints);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testAppendReloadsExistingEntryAfterUniqueConstraintRace(): void
    {
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: UniqueRaceLedgerConnection::class);
        assert($connection instanceof UniqueRaceLedgerConnection);
        $connection->executeStatement('CREATE UNIQUE INDEX ledger_entries_unique_memo ON ledger_entries (memo)');
        $connection->insert('ledger_entries', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => 1,
            'direction' => 'credit',
            'balance_after_points' => 1,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'append:unique-race-seed',
            'memo' => 'duplicate memo',
            'metadata_json' => null,
        ]);
        $connection->afterRolledBackUniqueConstraint = static function (Connection $connection): void {
            $connection->insert('ledger_entries', [
                'organization_id' => 10,
                'account_type' => 'advertiser_balance',
                'account_id' => null,
                'points_amount' => 500,
                'direction' => 'credit',
                'balance_after_points' => 500,
                'reference_type' => null,
                'reference_id' => null,
                'idempotency_key' => 'append:unique-race',
                'memo' => 'concurrent insert',
                'metadata_json' => null,
            ]);
        };

        $stored = (new PointsLedgerRepository($connection))->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'append:unique-race',
            memo: 'duplicate memo',
            metadata: null,
        ));

        self::assertSame(2, $stored->id);
        self::assertSame(500, $stored->balanceAfterPoints);
        self::assertSame('concurrent insert', $stored->memo);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testAppendRethrowsUniqueConstraintWhenNoIdempotentEntryExists(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $connection->executeStatement('CREATE UNIQUE INDEX ledger_entries_unique_memo ON ledger_entries (memo)');
        $connection->insert('ledger_entries', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => 1,
            'direction' => 'credit',
            'balance_after_points' => 1,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'append:unique-seed',
            'memo' => 'duplicate memo',
            'metadata_json' => null,
        ]);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        (new PointsLedgerRepository($connection))->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'append:unique-missing-existing',
            memo: 'duplicate memo',
            metadata: null,
        ));
    }

    public function testTryDebitReloadsExistingEntryAfterUniqueConstraintRace(): void
    {
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: UniqueRaceLedgerConnection::class);
        assert($connection instanceof UniqueRaceLedgerConnection);
        $connection->insert('ledger_account_balances', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'balance_points' => 500,
            'updated_at' => '2026-06-12 00:00:00',
        ]);
        $connection->executeStatement('CREATE UNIQUE INDEX ledger_entries_try_debit_unique_memo ON ledger_entries (memo)');
        $connection->insert('ledger_entries', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => 1,
            'direction' => 'credit',
            'balance_after_points' => 1,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'try-debit:unique-race-seed',
            'memo' => 'duplicate debit memo',
            'metadata_json' => null,
        ]);
        $connection->afterRolledBackUniqueConstraint = static function (Connection $connection): void {
            $connection->insert('ledger_entries', [
                'organization_id' => 10,
                'account_type' => 'advertiser_balance',
                'account_id' => null,
                'points_amount' => 100,
                'direction' => 'debit',
                'balance_after_points' => 400,
                'reference_type' => null,
                'reference_id' => null,
                'idempotency_key' => 'try-debit:unique-race',
                'memo' => 'concurrent debit',
                'metadata_json' => null,
            ]);
        };

        $stored = (new PointsLedgerRepository($connection))->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'try-debit:unique-race',
            memo: 'duplicate debit memo',
            metadata: null,
        ));

        self::assertSame(2, $stored?->id);
        self::assertSame(400, $stored?->balanceAfterPoints);
        self::assertSame('concurrent debit', $stored?->memo);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testTryDebitRethrowsUniqueConstraintWhenNoIdempotentEntryExists(): void
    {
        $connection = $this->createConnection(withBalanceTable: true);
        $connection->insert('ledger_account_balances', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'balance_points' => 500,
            'updated_at' => '2026-06-12 00:00:00',
        ]);
        $connection->executeStatement('CREATE UNIQUE INDEX ledger_entries_try_debit_unique_memo ON ledger_entries (memo)');
        $connection->insert('ledger_entries', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => 1,
            'direction' => 'credit',
            'balance_after_points' => 1,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'try-debit:unique-seed',
            'memo' => 'duplicate debit memo',
            'metadata_json' => null,
        ]);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        (new PointsLedgerRepository($connection))->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'try-debit:unique-missing-existing',
            memo: 'duplicate debit memo',
            metadata: null,
        ));
    }

    public function testMysqlBalanceRowsUseForUpdateAndUpsertSyntax(): void
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: MySqlLikeLedgerConnection::class);
        assert($connection instanceof MySqlLikeLedgerConnection);
        $connection->schemaManager = $schemaManager;
        $repository = new PointsLedgerRepository($connection);

        $stored = $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 1_000,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'mysql:balance-upsert',
            memo: null,
            metadata: null,
        ));

        self::assertSame(1_000, $stored->balanceAfterPoints);
        self::assertTrue($connection->sawForUpdate);
        self::assertTrue($connection->sawOnDuplicateKeyUpdate);
    }

    public function testBalanceTableDetectionFallsBackToLedgerSumWhenSchemaInspectionFails(): void
    {
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: MySqlLikeLedgerConnection::class);
        assert($connection instanceof MySqlLikeLedgerConnection);
        $connection->schemaInspectionFailure = new \RuntimeException('schema unavailable');
        $connection->insert('ledger_entries', [
            'organization_id' => 10,
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => 300,
            'direction' => 'credit',
            'balance_after_points' => 300,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'schema:fallback-credit',
            'memo' => null,
            'metadata_json' => null,
        ]);

        self::assertSame(300, (new PointsLedgerRepository($connection))->balanceForOrganization(10));
    }

    public function testLedgerWritesFailClosedWhenBalanceTableInspectionFails(): void
    {
        $connection = $this->createConnection(withBalanceTable: true, connectionClass: MySqlLikeLedgerConnection::class);
        assert($connection instanceof MySqlLikeLedgerConnection);
        $connection->schemaInspectionFailure = new \RuntimeException('schema unavailable');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ledger balance table is required for ledger writes.');

        (new PointsLedgerRepository($connection))->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'schema:write-fail-closed',
            memo: null,
            metadata: null,
        ));
    }

    public function testLedgerWritesFailClosedWhenBalanceTableIsMissing(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ledger balance table is required for ledger writes.');

        $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'schema:missing-balance-table',
            memo: null,
            metadata: null,
        ));
    }

    public function testLedgerWritesFailClosedWhenBalanceTableWasPreviouslyDetectedMissing(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        self::assertSame(0, $repository->balanceForOrganization(10));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ledger balance table is required for ledger writes.');

        $repository->append(new PointsLedgerEntry(
            id: null,
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 500,
            direction: LedgerDirection::Credit,
            balanceAfterPoints: null,
            referenceType: null,
            referenceId: null,
            idempotencyKey: 'schema:cached-missing-balance-table',
            memo: null,
            metadata: null,
        ));
    }

    public function testFindByIdReturnsNullForNonPositiveAndMissingRows(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        self::assertNull($repository->findById(0));
        self::assertNull($repository->findById(-5));
        self::assertNull($repository->findById(999));
    }

    public function testFindReversalForEntryReturnsNullForNonPositiveIds(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        self::assertNull($repository->findReversalForEntry(0));
        self::assertNull($repository->findReversalForEntry(-5));
    }

    public function testFindByIdempotencyKeyReturnsNullForBlankAndMissingRows(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        self::assertNull($repository->findByIdempotencyKey(''));
        self::assertNull($repository->findByIdempotencyKey('   '));
        self::assertNull($repository->findByIdempotencyKey('missing:key'));
    }

    public function testListAndBalanceForOrganizationUseNewestFirstAndSignedDirections(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection(withBalanceTable: true));
        $repository->append(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 1000, LedgerDirection::Credit, 1000, null, null, 'credit:1', null, null));
        $repository->append(new PointsLedgerEntry(null, 10, 'advertiser_balance', null, 250, LedgerDirection::Debit, 750, null, null, 'debit:1', null, null));
        $repository->append(new PointsLedgerEntry(null, 10, 'publisher_earnings', null, 400, LedgerDirection::Credit, 400, null, null, 'publisher:1', null, null));
        $repository->append(new PointsLedgerEntry(null, 11, 'advertiser_balance', null, 999, LedgerDirection::Credit, 999, null, null, 'other:1', null, null));

        $entries = $repository->listForOrganization(10, 2);

        self::assertCount(2, $entries);
        self::assertSame('publisher:1', $entries[0]->idempotencyKey);
        self::assertSame('debit:1', $entries[1]->idempotencyKey);
        self::assertSame(['publisher:1'], array_map(
            static fn (PointsLedgerEntry $entry): string => $entry->idempotencyKey,
            $repository->listForOrganization(10, 50, 'publisher_earnings'),
        ));
        self::assertSame(['publisher:1'], array_map(
            static fn (PointsLedgerEntry $entry): string => $entry->idempotencyKey,
            $repository->listForOrganization(10, 50, ' publisher_earnings '),
        ));
        self::assertSame(750, $repository->balanceForOrganization(10));
        self::assertSame(0, $repository->balanceForOrganization(0));
        self::assertSame([], $repository->listForOrganization(0));
    }

    public function testPointsLedgerEntryRejectsNonPositiveOrganizationId(): void
    {
        $this->assertInvalidEntry(
            ['organizationId' => 0],
            'Ledger organization ID must be positive.',
        );
    }

    public function testPointsLedgerEntryRejectsBlankAccountType(): void
    {
        $this->assertInvalidEntry(
            ['accountType' => '   '],
            'Ledger account type is required.',
        );
    }

    public function testPointsLedgerEntryRejectsNonPositiveAccountIdWhenProvided(): void
    {
        $this->assertInvalidEntry(
            ['accountId' => 0],
            'Ledger account ID must be positive when provided.',
        );
    }

    public function testPointsLedgerEntryRejectsNonPositivePointsAmount(): void
    {
        $this->assertInvalidEntry(
            ['pointsAmount' => 0],
            'Ledger points amount must be positive.',
        );
    }

    public function testPointsLedgerEntryRejectsBlankIdempotencyKey(): void
    {
        $this->assertInvalidEntry(
            ['idempotencyKey' => '   '],
            'Ledger idempotency key is required.',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function assertInvalidEntry(array $overrides, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new PointsLedgerEntry(...array_merge([
            'id' => null,
            'organizationId' => 1,
            'accountType' => 'advertiser_balance',
            'accountId' => 2,
            'pointsAmount' => 100,
            'direction' => LedgerDirection::Credit,
            'balanceAfterPoints' => null,
            'referenceType' => null,
            'referenceId' => null,
            'idempotencyKey' => 'ledger:key:1',
            'memo' => null,
            'metadata' => null,
        ], $overrides));
    }

    /**
     * @param class-string<Connection> $connectionClass
     */
    private function createConnection(bool $withBalanceTable = false, string $connectionClass = Connection::class): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($connectionClass !== Connection::class) {
            $params['wrapperClass'] = $connectionClass;
        }

        $connection = DriverManager::getConnection($params);
        if ($withBalanceTable) {
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
        }
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
    idempotency_key VARCHAR(160) NOT NULL,
    memo VARCHAR(255) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );

        return $connection;
    }
}

final class RaceInjectingLedgerConnection extends Connection
{
    /**
     * @var Closure(Connection): void|null
     */
    public ?Closure $beforeTransactional = null;

    /**
     * @template T
     * @param Closure(Connection): T $func
     * @return T
     */
    public function transactional(Closure $func): mixed
    {
        if ($this->beforeTransactional !== null) {
            $callback = $this->beforeTransactional;
            $this->beforeTransactional = null;
            $callback($this);
        }

        return parent::transactional($func);
    }
}

final class UniqueRaceLedgerConnection extends Connection
{
    /**
     * @var Closure(Connection): void|null
     */
    public ?Closure $afterRolledBackUniqueConstraint = null;

    /**
     * @template T
     * @param Closure(Connection): T $func
     * @return T
     */
    public function transactional(Closure $func): mixed
    {
        try {
            return parent::transactional($func);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            if ($this->afterRolledBackUniqueConstraint !== null) {
                $callback = $this->afterRolledBackUniqueConstraint;
                $this->afterRolledBackUniqueConstraint = null;
                $callback($this);
            }

            throw $exception;
        }
    }
}

final class MySqlLikeLedgerConnection extends Connection
{
    public ?AbstractSchemaManager $schemaManager = null;

    public ?\Throwable $schemaInspectionFailure = null;

    public bool $sawForUpdate = false;

    public bool $sawOnDuplicateKeyUpdate = false;

    public function createSchemaManager(): AbstractSchemaManager
    {
        if ($this->schemaInspectionFailure !== null) {
            throw $this->schemaInspectionFailure;
        }

        return $this->schemaManager ?? parent::createSchemaManager();
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new MySQL80Platform();
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type>|array<string, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (str_contains($query, ' FOR UPDATE')) {
            $this->sawForUpdate = true;
            $query = str_replace(' FOR UPDATE', '', $query);
        }

        return parent::fetchOne($query, $params, $types);
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @param array<int, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type>|array<string, string|\Doctrine\DBAL\ParameterType|\Doctrine\DBAL\Types\Type> $types
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $this->sawOnDuplicateKeyUpdate = true;
            $sql = preg_replace(
                '/INSERT INTO ledger_account_balances \(organization_id, account_type, balance_points, updated_at\) VALUES \(\?, \?, \?, \?\) ON DUPLICATE KEY UPDATE updated_at = updated_at/',
                'INSERT OR IGNORE INTO ledger_account_balances (organization_id, account_type, balance_points, updated_at) VALUES (?, ?, ?, ?)',
                $sql,
            ) ?? $sql;
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
