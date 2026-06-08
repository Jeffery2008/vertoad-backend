<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Tests\Billing\BillingTask14Schema;

final class WithdrawalProofTest extends TestCase
{
    public function testCreatesAndConfirmsWithdrawalProofUploadMetadata(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 2000, 'earning:proof');

        $withdrawals = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);
        $request = $withdrawals->requestWithdrawal(42, 7, 1000, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $withdrawals->markPaid($request->id ?? 0, 99, null, new DateTimeImmutable('2026-06-08 13:00:00'));

        $proofs = new WithdrawalProofService(
            new WithdrawalRepository($connection),
            new DeterministicPresignedUploadSigner([
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'withdrawal-proofs',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'path_style_endpoint' => true,
            ]),
            static fn (): string => 'proof-token',
        );

        $intent = $proofs->createUploadIntent(
            withdrawalRequestId: $request->id ?? 0,
            organizationId: 42,
            uploadedByUserId: 99,
            filename: 'payout.pdf',
            contentType: 'application/pdf',
            byteSize: 4096,
            now: new DateTimeImmutable('2026-06-08 14:00:00 UTC'),
        );

        self::assertStringStartsWith('withdrawals/42/' . $request->id . '/proof-token-payout.pdf', $intent->proof->objectKey);
        self::assertStringContainsString('/withdrawal-proofs/withdrawals/42/', $intent->upload->url);
        self::assertSame('pending_upload', $intent->proof->status);

        $confirmed = $proofs->confirmUploadedProof(
            proofId: $intent->proof->id ?? 0,
            withdrawalRequestId: $request->id ?? 0,
            organizationId: 42,
            uploadedByUserId: 99,
            objectKey: $intent->proof->objectKey,
            contentType: 'application/pdf',
            byteSize: 4096,
            checksum: 'sha256:abc',
            now: new DateTimeImmutable('2026-06-08 14:05:00 UTC'),
        );

        self::assertSame('confirmed', $confirmed->status);
        self::assertSame('sha256:abc', $confirmed->checksum);
    }

    public function testRejectsInvalidWithdrawalProofMetadataAndConfirmation(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $proofs = new WithdrawalProofService(
            new WithdrawalRepository($connection),
            new DeterministicPresignedUploadSigner([
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'withdrawal-proofs',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'path_style_endpoint' => true,
            ]),
            static fn (): string => 'proof-token',
        );

        $this->assertInvalidProof(
            static fn () => $proofs->createUploadIntent(0, 42, 99, 'payout.pdf', 'application/pdf', 4096, new DateTimeImmutable('2026-06-08 14:00:00 UTC')),
            'Withdrawal proof metadata is invalid.',
        );
        $this->assertInvalidProof(
            static fn () => $proofs->confirmUploadedProof(0, 1, 42, 99, '', 'application/pdf', 4096, '', new DateTimeImmutable('2026-06-08 14:05:00 UTC')),
            'Withdrawal proof confirmation is invalid.',
        );
    }

    private function assertInvalidProof(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            return;
        }

        self::fail('Expected invalid withdrawal proof operation.');
    }
}
