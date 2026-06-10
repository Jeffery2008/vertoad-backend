<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyStatus;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;

final class RechargeKeyServiceTest extends TestCase
{
    public function testIssueStoresEncryptedPlaintextHashBatchMetadataAndPositiveIntegerPoints(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        $key = $service->issue(
            plaintextKey: ' rk_live_ABC123 ',
            pointsAmount: 500,
            batchCode: ' june-topups ',
            batchMetadata: ['z' => 'last', 'a' => ['second' => 2, 'first' => 1]],
            expiresAt: new DateTimeImmutable('2026-06-30 00:00:00'),
            issuedByUserId: 7,
            organizationId: 10,
        );

        self::assertSame(1, $key->id);
        self::assertSame(10, $key->organizationId);
        self::assertSame(hash('sha256', 'rk_live_ABC123'), $key->keyHash);
        self::assertSame('encrypted:rk_live_ABC123', $key->encryptedPlaintextKey);
        self::assertSame(500, $key->pointsAmount);
        self::assertSame(RechargeKeyStatus::Issued, $key->status);
        self::assertSame('june-topups', $key->batchCode);
        self::assertSame(['a' => ['first' => 1, 'second' => 2], 'z' => 'last'], $key->batchMetadata);
        self::assertSame(7, $key->issuedByUserId);
    }

    public function testIssueRejectsBlankPlaintextAndNonPositivePoints(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge key plaintext is required.');

        $service->issue(
            plaintextKey: ' ',
            pointsAmount: 0,
            batchCode: null,
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
            organizationId: null,
        );
    }

    public function testIssueRejectsNonPositivePoints(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge key points amount must be positive.');

        $service->issue(
            plaintextKey: 'rk_live_ABC123',
            pointsAmount: 0,
            batchCode: null,
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
            organizationId: null,
        );
    }

    public function testIssueRejectsDuplicatePlaintextKey(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        $service->issue('rk_live_ABC123', 100, null, null, null, 7, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key already exists.');

        $service->issue(' rk_live_ABC123 ', 100, null, null, null, 7, null);
    }

    public function testGenerateBatchCreatesUniquePlaintextKeysWithBatchMetadataAndIssuer(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $auditRepository = new CapturingRechargeAuditRepository();
        $generatedPlaintexts = ['rk_live_BATCH_A', 'rk_live_BATCH_B'];
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: function () use (&$generatedPlaintexts): string {
                return array_shift($generatedPlaintexts) ?? 'rk_live_OVERFLOW';
            },
            audit: new AuditLogService($auditRepository),
        );

        $keys = $service->generateBatch(
            pointsAmount: 1500,
            count: 2,
            batchCode: ' batch-2026-06 ',
            batchMetadata: ['z' => 'last', 'a' => ['second' => 2, 'first' => 1]],
            expiresAt: new DateTimeImmutable('2026-12-31 23:59:59'),
            issuedByUserId: 7,
        );

        self::assertCount(2, $keys);
        self::assertSame('rk_live_BATCH_A', $keys[0]->plaintextKey);
        self::assertSame('rk_live_BATCH_B', $keys[1]->plaintextKey);
        self::assertSame(1, $keys[0]->key->id);
        self::assertSame(2, $keys[1]->key->id);
        self::assertSame(hash('sha256', 'rk_live_BATCH_A'), $keys[0]->key->keyHash);
        self::assertSame('encrypted:rk_live_BATCH_A', $keys[0]->key->encryptedPlaintextKey);
        self::assertSame(1500, $keys[0]->key->pointsAmount);
        self::assertSame('batch-2026-06', $keys[0]->key->batchCode);
        self::assertSame(['a' => ['first' => 1, 'second' => 2], 'z' => 'last'], $keys[0]->key->batchMetadata);
        self::assertSame('2026-12-31 23:59:59', $keys[0]->key->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame(7, $keys[0]->key->issuedByUserId);
        self::assertCount(1, $auditRepository->entries);
        self::assertSame('billing.recharge_key.generate_batch', $auditRepository->entries[0]->action);
        self::assertSame('recharge_key_batch', $auditRepository->entries[0]->subjectType);
        self::assertNull($auditRepository->entries[0]->subjectId);
        self::assertSame(7, $auditRepository->entries[0]->actorUserId);
        self::assertSame([
            'batch_code' => 'batch-2026-06',
            'count' => 2,
            'expires_at' => '2026-12-31 23:59:59',
            'generated_key_ids' => [1, 2],
            'points_amount' => 1500,
        ], $auditRepository->entries[0]->metadata);
        self::assertStringNotContainsString(
            'rk_live_BATCH_A',
            json_encode($auditRepository->entries[0]->metadata, JSON_THROW_ON_ERROR),
        );
    }

    public function testGenerateBatchRejectsInvalidCountAndIssuer(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: static fn (): string => 'rk_live_UNUSED',
            audit: new AuditLogService(new CapturingRechargeAuditRepository()),
        );

        foreach ([0, 501] as $count) {
            try {
                $service->generateBatch(
                    pointsAmount: 100,
                    count: $count,
                    batchCode: 'batch',
                    batchMetadata: null,
                    expiresAt: null,
                    issuedByUserId: 7,
                );
                self::fail('Expected invalid batch count to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Recharge key batch count must be between 1 and 500.', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge key issuer user ID must be positive.');

        $service->generateBatch(
            pointsAmount: 100,
            count: 1,
            batchCode: 'batch',
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 0,
        );
    }

    public function testGenerateBatchRetriesDuplicateGeneratedPlaintext(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $generatedPlaintexts = ['rk_live_DUPLICATE', 'rk_live_DUPLICATE', 'rk_live_UNIQUE'];
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: function () use (&$generatedPlaintexts): string {
                return array_shift($generatedPlaintexts) ?? 'rk_live_OVERFLOW';
            },
            audit: new AuditLogService(new CapturingRechargeAuditRepository()),
        );
        $service->issue('rk_live_DUPLICATE', 100, null, null, null, 7, null);

        $keys = $service->generateBatch(
            pointsAmount: 200,
            count: 1,
            batchCode: 'batch',
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
        );

        self::assertSame('rk_live_UNIQUE', $keys[0]->plaintextKey);
        self::assertSame(2, $keys[0]->key->id);
    }

    public function testGenerateBatchPropagatesNonDuplicateGenerationFailures(): void
    {
        $service = new RechargeKeyService(
            repository: new FailingGeneratedRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: static fn (): string => 'rk_live_FAILING_STORAGE',
            audit: new AuditLogService(new CapturingRechargeAuditRepository()),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key storage is unavailable.');

        $service->generateBatch(
            pointsAmount: 100,
            count: 1,
            batchCode: null,
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
        );
    }

    public function testGenerateBatchReturnsNormalizedPlaintextMatchingStoredCiphertext(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: static fn (): string => ' rk_live_TRIMMED ',
            audit: new AuditLogService(new CapturingRechargeAuditRepository()),
        );

        $keys = $service->generateBatch(
            pointsAmount: 200,
            count: 1,
            batchCode: 'batch',
            batchMetadata: null,
            expiresAt: null,
            issuedByUserId: 7,
        );

        self::assertSame('rk_live_TRIMMED', $keys[0]->plaintextKey);
        self::assertSame('encrypted:rk_live_TRIMMED', $keys[0]->key->encryptedPlaintextKey);
    }

    public function testGenerateBatchRejectsMissingAuditServiceBeforeIssuingKeys(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: static fn (): string => 'rk_live_NO_AUDIT',
        );

        try {
            $service->generateBatch(
                pointsAmount: 100,
                count: 1,
                batchCode: null,
                batchMetadata: null,
                expiresAt: null,
                issuedByUserId: 7,
            );
            self::fail('Expected missing audit service to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Audit log service is required to generate recharge key batches.', $exception->getMessage());
        }

        self::assertSame(0, $repository->count());
    }

    public function testGenerateBatchRollsBackGeneratedKeysWhenAuditFails(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            plaintextGenerator: static fn (): string => 'rk_live_AUDIT_FAIL',
            audit: new AuditLogService(new FailingRechargeAuditRepository()),
        );

        try {
            $service->generateBatch(
                pointsAmount: 100,
                count: 1,
                batchCode: null,
                batchMetadata: null,
                expiresAt: null,
                issuedByUserId: 7,
            );
            self::fail('Expected audit failure to reject the generated batch.');
        } catch (RuntimeException $exception) {
            self::assertSame('Audit log storage is unavailable.', $exception->getMessage());
        }

        self::assertSame(0, $repository->count());
    }

    public function testRevealPlaintextDecryptsKeyAndRecordsAuditWithoutPlaintext(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $auditRepository = new CapturingRechargeAuditRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            audit: new AuditLogService($auditRepository),
        );
        $issued = $service->issue('rk_live_SECRET', 100, 'batch-secret', ['source' => 'admin'], null, 7, null);

        $reveal = $service->revealPlaintext(
            keyId: (int) $issued->id,
            actorUserId: 99,
            ipAddress: '127.0.0.1',
            userAgent: 'Console/1.0',
        );

        self::assertSame($issued->id, $reveal->key->id);
        self::assertSame('rk_live_SECRET', $reveal->plaintextKey);
        self::assertCount(1, $auditRepository->entries);
        self::assertSame('billing.recharge_key.reveal_plaintext', $auditRepository->entries[0]->action);
        self::assertSame('recharge_key', $auditRepository->entries[0]->subjectType);
        self::assertSame($issued->id, $auditRepository->entries[0]->subjectId);
        self::assertSame(99, $auditRepository->entries[0]->actorUserId);
        self::assertSame('batch-secret', $auditRepository->entries[0]->metadata['batch_code'] ?? null);
        self::assertSame(100, $auditRepository->entries[0]->metadata['points_amount'] ?? null);
        self::assertStringNotContainsString('rk_live_SECRET', json_encode($auditRepository->entries[0]->metadata, JSON_THROW_ON_ERROR));
    }

    public function testRevealPlaintextRejectsMissingKeyInvalidActorAndMissingAuditService(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        try {
            $service->revealPlaintext(1, 7, null, null);
            self::fail('Expected missing audit service to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Audit log service is required to reveal recharge key plaintext.', $exception->getMessage());
        }

        $serviceWithAudit = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
            audit: new AuditLogService(new CapturingRechargeAuditRepository()),
        );

        try {
            $serviceWithAudit->revealPlaintext(0, 7, null, null);
            self::fail('Expected invalid key ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Recharge key ID must be positive.', $exception->getMessage());
        }

        try {
            $serviceWithAudit->revealPlaintext(1, 0, null, null);
            self::fail('Expected invalid actor to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Recharge key plaintext reveal actor user ID must be positive.', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key was not found.');

        $serviceWithAudit->revealPlaintext(1, 7, null, null);
    }

    public function testRedeemCreditsAdvertiserBalanceAndMarksKeyRedeemed(): void
    {
        $rechargeRepository = new FakeRechargeKeyRepository();
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: $rechargeRepository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue('rk_live_ABC123', 800, 'batch-1', ['channel' => 'manual'], null, 7, null);

        $redemption = $service->redeem(
            plaintextKey: ' rk_live_ABC123 ',
            organizationId: 42,
            redeemedByUserId: 9,
            now: new DateTimeImmutable('2026-06-07 10:00:00'),
        );

        self::assertSame(RechargeKeyStatus::Redeemed, $redemption->key->status);
        self::assertSame(42, $redemption->key->organizationId);
        self::assertSame(9, $redemption->key->redeemedByUserId);
        self::assertSame(1, $redemption->key->redeemedLedgerEntryId);
        self::assertSame(800, $redemption->ledgerEntry->pointsAmount);
        self::assertSame(LedgerDirection::Credit, $redemption->ledgerEntry->direction);
        self::assertSame('advertiser_balance', $redemption->ledgerEntry->accountType);
        self::assertSame('recharge_key', $redemption->ledgerEntry->referenceType);
        self::assertSame(1, $redemption->ledgerEntry->referenceId);
        self::assertSame('recharge_key:1:redeem', $redemption->ledgerEntry->idempotencyKey);
        self::assertSame(
            [
                'batch_code' => 'batch-1',
                'entry_kind' => 'recharge_redemption',
                'redeemed_by_user_id' => 9,
            ],
            $redemption->ledgerEntry->metadata,
        );
        self::assertCount(1, $ledgerRepository->entries);
    }

    public function testRedeemRejectsStoredKeyWithoutIdBeforeLedgerCredit(): void
    {
        $repository = new FakeRechargeKeyRepository(assignIds: false);
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue('rk_live_NO_ID', 800, null, null, null, 7, null);

        try {
            $service->redeem('rk_live_NO_ID', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
            self::fail('Expected recharge key without ID to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Recharge key ID is required for redemption.', $exception->getMessage());
        }

        self::assertCount(0, $ledgerRepository->entries);
    }

    public function testRedeemRejectsLedgerCreditWithoutIdBeforeMarkingRedeemed(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $ledgerRepository = new FakeRechargeLedgerRepository(assignIds: false);
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue('rk_live_NO_LEDGER_ID', 800, null, null, null, 7, null);

        try {
            $service->redeem('rk_live_NO_LEDGER_ID', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
            self::fail('Expected ledger entry without ID to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Recharge redemption ledger entry ID is required.', $exception->getMessage());
        }

        $key = $repository->findByKeyHash(hash('sha256', 'rk_live_NO_LEDGER_ID'));
        self::assertSame(RechargeKeyStatus::Issued, $key?->status);
        self::assertCount(1, $ledgerRepository->entries);
        self::assertNull($ledgerRepository->entries[0]->id);
    }

    public function testRedeemIsIdempotentForAlreadyRedeemedSameOrganizationAndUser(): void
    {
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue('rk_live_ABC123', 800, null, null, null, 7, null);

        $first = $service->redeem('rk_live_ABC123', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
        $second = $service->redeem('rk_live_ABC123', 42, 9, new DateTimeImmutable('2026-06-07 10:01:00'));

        self::assertSame($first->key->redeemedLedgerEntryId, $second->key->redeemedLedgerEntryId);
        self::assertSame($first->ledgerEntry->id, $second->ledgerEntry->id);
        self::assertCount(1, $ledgerRepository->entries);
    }

    public function testRedeemRejectsMissingKey(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key was not found.');

        $service->redeem('missing', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
    }

    public function testRedeemRejectsAlreadyRedeemedByDifferentTarget(): void
    {
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue('rk_live_ABC123', 800, null, null, null, 7, null);
        $service->redeem('rk_live_ABC123', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key has already been redeemed.');

        $service->redeem('rk_live_ABC123', 43, 9, new DateTimeImmutable('2026-06-07 10:01:00'));
    }

    public function testRedeemRejectsNonPositiveOrganizationAndUserIds(): void
    {
        $service = new RechargeKeyService(
            repository: new FakeRechargeKeyRepository(),
            ledger: new PointsLedgerService(new FakeRechargeLedgerRepository()),
            cipher: new FakeRechargeKeyCipher(),
        );

        try {
            $service->redeem('rk_live_ABC123', 0, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
            self::fail('Expected non-positive organization ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Recharge redemption organization ID must be positive.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge redemption user ID must be positive.');

        $service->redeem('rk_live_ABC123', 42, 0, new DateTimeImmutable('2026-06-07 10:00:00'));
    }

    public function testRedeemRejectsAlreadyExpiredStatusWithoutLedgerCredit(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $key = $service->issue('rk_live_EXPIRED_STATUS', 800, null, null, null, 7, null);
        $repository->store($key->withStatus(RechargeKeyStatus::Expired));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key has expired.');

        try {
            $service->redeem('rk_live_EXPIRED_STATUS', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
        } finally {
            self::assertCount(0, $ledgerRepository->entries);
        }
    }

    public function testRedeemRejectsRevokedKeyWithoutLedgerCredit(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $key = $service->issue('rk_live_REVOKED', 800, null, null, null, 7, null);
        $repository->store($key->withStatus(RechargeKeyStatus::Revoked));

        try {
            $service->redeem('rk_live_REVOKED', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
            self::fail('Expected revoked recharge key to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Recharge key has been revoked.', $exception->getMessage());
        }

        self::assertCount(0, $ledgerRepository->entries);
    }

    public function testRedeemTransitionsPastDueIssuedKeyToExpiredWithoutLedgerCredit(): void
    {
        $repository = new FakeRechargeKeyRepository();
        $ledgerRepository = new FakeRechargeLedgerRepository();
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService($ledgerRepository),
            cipher: new FakeRechargeKeyCipher(),
        );
        $service->issue(
            plaintextKey: 'rk_live_EXPIRED',
            pointsAmount: 800,
            batchCode: null,
            batchMetadata: null,
            expiresAt: new DateTimeImmutable('2026-06-01 00:00:00'),
            issuedByUserId: 7,
            organizationId: null,
        );

        try {
            $service->redeem('rk_live_EXPIRED', 42, 9, new DateTimeImmutable('2026-06-07 10:00:00'));
            self::fail('Expected expired recharge key to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Recharge key has expired.', $exception->getMessage());
        }

        $expired = $repository->findByKeyHash(hash('sha256', 'rk_live_EXPIRED'));
        self::assertSame(RechargeKeyStatus::Expired, $expired?->status);
        self::assertCount(0, $ledgerRepository->entries);
    }
}

final class FakeRechargeKeyCipher implements RechargeKeyPlaintextCipherInterface
{
    public function encrypt(string $plaintext): string
    {
        return 'encrypted:' . $plaintext;
    }

    public function decrypt(string $ciphertext): string
    {
        return str_starts_with($ciphertext, 'encrypted:') ? substr($ciphertext, 10) : $ciphertext;
    }
}

final class FakeRechargeKeyRepository implements RechargeKeyRepositoryInterface
{
    /** @var array<string, RechargeKey> */
    private array $keysByHash = [];

    /** @var array<int, RechargeKey> */
    private array $keysById = [];

    public function __construct(private readonly bool $assignIds = true)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $keysByHash = $this->keysByHash;
        $keysById = $this->keysById;

        try {
            return $operation();
        } catch (\Throwable $exception) {
            $this->keysByHash = $keysByHash;
            $this->keysById = $keysById;

            throw $exception;
        }
    }

    public function store(RechargeKey $key): RechargeKey
    {
        $stored = $this->assignIds && $key->id === null ? $key->withId(count($this->keysByHash) + 1) : $key;
        $this->keysByHash[$stored->keyHash] = $stored;
        if ($stored->id !== null) {
            $this->keysById[$stored->id] = $stored;
        }

        return $stored;
    }

    public function findById(int $id): ?RechargeKey
    {
        return $this->keysById[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->keysByHash);
    }

    public function findByKeyHash(string $keyHash): ?RechargeKey
    {
        return $this->keysByHash[$keyHash] ?? null;
    }

    public function markExpired(RechargeKey $key): RechargeKey
    {
        return $this->store($key->withStatus(RechargeKeyStatus::Expired));
    }

    public function markRedeemed(
        RechargeKey $key,
        int $organizationId,
        int $redeemedByUserId,
        int $ledgerEntryId,
        DateTimeImmutable $redeemedAt,
    ): RechargeKey {
        return $this->store($key->withRedemption($organizationId, $redeemedByUserId, $ledgerEntryId, $redeemedAt));
    }
}

final class FailingGeneratedRechargeKeyRepository implements RechargeKeyRepositoryInterface
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function store(RechargeKey $key): RechargeKey
    {
        return $key;
    }

    public function findById(int $id): ?RechargeKey
    {
        return null;
    }

    public function findByKeyHash(string $keyHash): ?RechargeKey
    {
        throw new RuntimeException('Recharge key storage is unavailable.');
    }

    public function markExpired(RechargeKey $key): RechargeKey
    {
        return $key;
    }

    public function markRedeemed(
        RechargeKey $key,
        int $organizationId,
        int $redeemedByUserId,
        int $ledgerEntryId,
        DateTimeImmutable $redeemedAt,
    ): RechargeKey {
        return $key;
    }
}

final class CapturingRechargeAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class FailingRechargeAuditRepository implements AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
        throw new RuntimeException('Audit log storage is unavailable.');
    }
}

final class FakeRechargeLedgerRepository implements PointsLedgerRepositoryInterface
{
    /** @var list<PointsLedgerEntry> */
    public array $entries = [];

    public function __construct(private readonly bool $assignIds = true)
    {
    }

    public function append(PointsLedgerEntry $entry): PointsLedgerEntry
    {
        $stored = new PointsLedgerEntry(
            id: $this->assignIds ? count($this->entries) + 1 : null,
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

    public function findReversalForEntry(int $entryId): ?PointsLedgerEntry
    {
        foreach (array_reverse($this->entries) as $entry) {
            if (
                $entry->referenceType === 'ledger_entry'
                && $entry->referenceId === $entryId
                && ($entry->metadata['entry_kind'] ?? null) === 'reversal'
            ) {
                return $entry;
            }
        }

        return null;
    }

    public function listForOrganization(int $organizationId, int $limit = 50, ?string $accountType = null): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            fn (PointsLedgerEntry $entry): bool => $entry->organizationId === $organizationId
                && ($accountType === null || $entry->accountType === trim($accountType)),
        ));

        return array_slice(array_reverse($entries), 0, max(1, min(200, $limit)));
    }

    public function balanceForOrganization(int $organizationId, string $accountType = 'advertiser_balance'): int
    {
        $balance = 0;
        foreach ($this->entries as $entry) {
            if ($entry->organizationId !== $organizationId || $entry->accountType !== trim($accountType)) {
                continue;
            }

            $balance += $entry->direction === LedgerDirection::Credit ? $entry->pointsAmount : -$entry->pointsAmount;
        }

        return $balance;
    }
}
