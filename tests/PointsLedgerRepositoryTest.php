<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\PointsLedgerRepository;

final class PointsLedgerRepositoryTest extends TestCase
{
    public function testAppendPersistsCreditEntryAndFindByIdHydratesMetadataJson(): void
    {
        $connection = $this->createConnection();
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
        self::assertSame(2500, (int) $row['balance_after_points']);
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
        self::assertSame(2500, $found->balanceAfterPoints);
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
        $connection = $this->createConnection();
        $repository = new PointsLedgerRepository($connection);

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
        self::assertNull($row['balance_after_points']);
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
        self::assertNull($found->balanceAfterPoints);
        self::assertNull($found->referenceType);
        self::assertNull($found->referenceId);
        self::assertSame('click:charge:1', $found->idempotencyKey);
        self::assertNull($found->memo);
        self::assertNull($found->metadata);
    }

    public function testFindByIdReturnsNullForNonPositiveAndMissingRows(): void
    {
        $repository = new PointsLedgerRepository($this->createConnection());

        self::assertNull($repository->findById(0));
        self::assertNull($repository->findById(-5));
        self::assertNull($repository->findById(999));
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
        $repository = new PointsLedgerRepository($this->createConnection());
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
