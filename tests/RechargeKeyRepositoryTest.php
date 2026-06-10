<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyStatus;
use VertoAD\Repository\RechargeKeyRepository;

final class RechargeKeyRepositoryTest extends TestCase
{
    public function testStoreAndFindByHashHydratesBatchMetadataAndDates(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new RechargeKeyRepository($connection);

        $stored = $repository->store(new RechargeKey(
            id: null,
            organizationId: null,
            keyHash: hash('sha256', 'rk_live_ABC123'),
            encryptedPlaintextKey: 'encrypted:rk_live_ABC123',
            pointsAmount: 500,
            status: RechargeKeyStatus::Issued,
            batchCode: 'batch-1',
            batchMetadata: ['a' => 1],
            expiresAt: new DateTimeImmutable('2026-06-30 00:00:00'),
            issuedByUserId: 7,
            redeemedByUserId: null,
            redeemedLedgerEntryId: null,
            redeemedAt: null,
        ));

        $found = $repository->findByKeyHash($stored->keyHash);

        self::assertNotNull($found);
        self::assertSame(1, $found->id);
        self::assertNull($found->organizationId);
        self::assertSame('encrypted:rk_live_ABC123', $found->encryptedPlaintextKey);
        self::assertSame(500, $found->pointsAmount);
        self::assertSame(RechargeKeyStatus::Issued, $found->status);
        self::assertSame('batch-1', $found->batchCode);
        self::assertSame(['a' => 1], $found->batchMetadata);
        self::assertSame('2026-06-30 00:00:00', $found->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame(7, $found->issuedByUserId);
    }

    public function testMarkRedeemedUpdatesRedemptionState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new RechargeKeyRepository($connection);
        $key = $repository->store(new RechargeKey(
            id: null,
            organizationId: null,
            keyHash: hash('sha256', 'rk_live_ABC123'),
            encryptedPlaintextKey: 'encrypted:rk_live_ABC123',
            pointsAmount: 500,
            status: RechargeKeyStatus::Issued,
            batchCode: null,
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
            redeemedByUserId: null,
            redeemedLedgerEntryId: null,
            redeemedAt: null,
        ));

        $redeemed = $repository->markRedeemed(
            key: $key,
            organizationId: 42,
            redeemedByUserId: 9,
            ledgerEntryId: 88,
            redeemedAt: new DateTimeImmutable('2026-06-07 10:00:00'),
        );

        $found = $repository->findByKeyHash($key->keyHash);

        self::assertSame(RechargeKeyStatus::Redeemed, $redeemed->status);
        self::assertSame(RechargeKeyStatus::Redeemed, $found?->status);
        self::assertSame(42, $found?->organizationId);
        self::assertSame(9, $found?->redeemedByUserId);
        self::assertSame(88, $found?->redeemedLedgerEntryId);
        self::assertSame('2026-06-07 10:00:00', $found?->redeemedAt?->format('Y-m-d H:i:s'));
    }

    public function testFindByIdHydratesStoredRechargeKeyAndRejectsInvalidIds(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new RechargeKeyRepository($connection);
        $stored = $repository->store(new RechargeKey(
            id: null,
            organizationId: 9,
            keyHash: hash('sha256', 'rk_live_BY_ID'),
            encryptedPlaintextKey: 'encrypted:rk_live_BY_ID',
            pointsAmount: 750,
            status: RechargeKeyStatus::Issued,
            batchCode: 'batch-by-id',
            batchMetadata: ['purpose' => 'admin'],
            expiresAt: null,
            issuedByUserId: 7,
            redeemedByUserId: null,
            redeemedLedgerEntryId: null,
            redeemedAt: null,
        ));

        $found = $repository->findById((int) $stored->id);

        self::assertNotNull($found);
        self::assertSame($stored->id, $found->id);
        self::assertSame(9, $found->organizationId);
        self::assertSame('batch-by-id', $found->batchCode);
        self::assertSame(['purpose' => 'admin'], $found->batchMetadata);
        self::assertNull($repository->findById(0));
        self::assertNull($repository->findById(-1));
        self::assertNull($repository->findById(999));
    }

    public function testFindByBlankHashReturnsNullWithoutQueryingAndMarkExpiredUpdatesStatus(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new RechargeKeyRepository($connection);

        self::assertNull($repository->findByKeyHash('   '));

        $key = $repository->store(new RechargeKey(
            id: null,
            organizationId: 5,
            keyHash: hash('sha256', 'rk_live_ABC123'),
            encryptedPlaintextKey: 'encrypted:rk_live_ABC123',
            pointsAmount: 500,
            status: RechargeKeyStatus::Issued,
            batchCode: null,
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: null,
            redeemedByUserId: null,
            redeemedLedgerEntryId: null,
            redeemedAt: null,
        ));

        $expired = $repository->markExpired($key);
        $found = $repository->findByKeyHash($key->keyHash);

        self::assertSame(RechargeKeyStatus::Expired, $expired->status);
        self::assertSame(RechargeKeyStatus::Expired, $found?->status);
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE recharge_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NULL,
    key_hash CHAR(64) NOT NULL,
    encrypted_plaintext_key BLOB NOT NULL,
    points_amount INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    batch_code VARCHAR(120) NULL,
    batch_metadata_json TEXT NULL,
    issued_by_user_id INTEGER NULL,
    redeemed_by_user_id INTEGER NULL,
    redeemed_ledger_entry_id INTEGER NULL,
    expires_at DATETIME NULL,
    redeemed_at DATETIME NULL
)
SQL
        );
    }
}
