<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalProofPolicy;
use VertoAD\Domain\Billing\WithdrawalProofStatus;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUpload;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalProofValidationException;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Tests\Assets\InMemoryObjectStorageInspector;
use VertoAD\Tests\Billing\BillingTask14Schema;

final class WithdrawalProofTest extends TestCase
{
    public function testServerInspectionVerifiesObjectAndConfirmationIsIdempotent(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $intent = $proofs->createUploadIntent(
            withdrawalRequestId: (int) $request->id,
            uploadedByUserId: 99,
            filename: '../bank receipt.PDF',
            contentType: 'APPLICATION/PDF',
            byteSize: 4_096,
            now: $this->at('14:00'),
        );

        self::assertMatchesRegularExpression(
            '~^withdrawals/42/' . $request->id . '/proof-token-bank-receipt\.PDF$~',
            $intent->proof->objectKey,
        );
        self::assertSame(WithdrawalProofStatus::PendingUpload, $intent->proof->status);
        self::assertSame('PUT', $intent->upload->method);
        self::assertSame($intent->proof->objectKey, $intent->upload->objectKey);

        $body = "%PDF-1.7\nserver inspected payout receipt";
        $checksum = 'sha256:' . hash('sha256', $body);
        $inspector->put(new StoredObjectInspection(
            objectKey: $intent->proof->objectKey,
            contentType: 'application/pdf',
            byteSize: 4_096,
            width: 1,
            height: 1,
            durationSeconds: null,
            checksum: $checksum,
            leadingBytes: $body,
        ));

        $verified = $proofs->confirmUploadedProof(
            proofId: (int) $intent->proof->id,
            withdrawalRequestId: (int) $request->id,
            actorUserId: 99,
            now: $this->at('14:05'),
        );
        $replay = $proofs->confirmUploadedProof(
            proofId: (int) $intent->proof->id,
            withdrawalRequestId: (int) $request->id,
            actorUserId: 100,
            now: $this->at('14:06'),
        );

        self::assertSame(WithdrawalProofStatus::Verified, $verified->status);
        self::assertSame($checksum, $verified->checksum);
        self::assertNotNull($verified->verificationAttemptedAt);
        self::assertNotNull($verified->verifiedAt);
        self::assertEquals($verified->verifiedAt, $replay->verifiedAt);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_verified'"));
    }

    #[DataProvider('imageProofProvider')]
    public function testServerInspectionAcceptsSupportedImageMagic(
        string $filename,
        string $contentType,
        string $body,
    ): void {
        [, $repository, $request] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $intent = $proofs->createUploadIntent(
            (int) $request->id,
            99,
            $filename,
            $contentType,
            strlen($body),
            $this->at('14:00'),
        );
        $inspector->put($this->stored(
            $intent->proof,
            'sha256:' . hash('sha256', $body),
            $body,
        ));

        $verified = $this->confirm($proofs, $intent->proof, $request, $this->at('14:05'));

        self::assertSame(WithdrawalProofStatus::Verified, $verified->status);
        self::assertSame(strtolower($contentType), $verified->contentType);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function imageProofProvider(): iterable
    {
        yield 'JPEG receipt' => ['receipt.JPG', 'IMAGE/JPEG', "\xFF\xD8\xFF\xE0bank receipt"];
        yield 'PNG receipt' => ['receipt.png', 'image/png', "\x89PNG\r\n\x1a\nbank receipt"];
    }

    public function testUploadIntentFailsClosedWhenWithdrawalOwnershipChangesDuringSigning(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $signer = new class($connection, (int) $request->id, $this->signer()) implements ObjectStorageUploadSignerInterface {
            public function __construct(
                private readonly Connection $connection,
                private readonly int $withdrawalRequestId,
                private readonly ObjectStorageUploadSignerInterface $delegate,
            ) {
            }

            public function presignPut(PresignedUploadRequest $request): PresignedUpload
            {
                $this->connection->update(
                    'withdrawal_requests',
                    ['organization_id' => 84],
                    ['id' => $this->withdrawalRequestId],
                );

                return $this->delegate->presignPut($request);
            }
        };
        $proofs = new WithdrawalProofService(
            $repository,
            $signer,
            static fn (): string => 'proof-token',
            new InMemoryObjectStorageInspector(),
        );

        $failure = $this->captureProofFailure(
            fn () => $proofs->createUploadIntent(
                (int) $request->id,
                99,
                'receipt.pdf',
                'application/pdf',
                1_024,
                $this->at('14:00'),
            ),
        );

        self::assertSame('withdrawal_not_found', $failure->errorCode);
        self::assertSame(404, $failure->status);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM withdrawal_proofs'));
    }

    public function testMissingObjectStaysPendingAndCanBeRetried(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $intent = $this->intent($proofs, $request);

        $missing = $this->captureProofFailure(fn () => $this->confirm($proofs, $intent->proof, $request, $this->at('14:05')));
        self::assertSame('withdrawal_proof_object_not_found', $missing->errorCode);
        self::assertSame(404, $missing->status);
        self::assertSame(WithdrawalProofStatus::PendingUpload, $repository->findProof((int) $intent->proof->id)?->status);

        $body = "%PDF-1.7\nlate object";
        $inspector->put($this->stored($intent->proof, checksum: 'sha256:' . hash('sha256', $body), leadingBytes: $body));
        $verified = $this->confirm($proofs, $intent->proof, $request, $this->at('14:10'));
        self::assertSame(WithdrawalProofStatus::Verified, $verified->status);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_verification_deferred'"));
    }

    public function testUnavailableInspectorFailsClosedWithoutPermanentlyRejectingProof(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $workingInspector = new InMemoryObjectStorageInspector();
        $intent = $this->intent($this->proofService($repository, $workingInspector), $request);
        $unavailable = new class implements ObjectStorageInspectorInterface {
            public function inspect(string $objectKey): ?StoredObjectInspection
            {
                throw new RuntimeException('network timeout');
            }
        };
        $proofs = $this->proofService($repository, $unavailable);

        $failure = $this->captureProofFailure(fn () => $this->confirm($proofs, $intent->proof, $request, $this->at('14:05')));
        self::assertSame('withdrawal_proof_verification_unavailable', $failure->errorCode);
        self::assertSame(503, $failure->status);
        self::assertSame(WithdrawalProofStatus::PendingUpload, $repository->findProof((int) $intent->proof->id)?->status);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_verification_deferred'"));

        $noInspector = $this->proofService($repository, null);
        $notConfigured = $this->captureProofFailure(fn () => $this->confirm($noInspector, $intent->proof, $request, $this->at('14:06')));
        self::assertSame('withdrawal_proof_verification_unavailable', $notConfigured->errorCode);
        self::assertSame(WithdrawalProofStatus::PendingUpload, $repository->findProof((int) $intent->proof->id)?->status);
    }

    /**
     * @param callable(WithdrawalProof): StoredObjectInspection $storedFactory
     */
    #[DataProvider('invalidStoredObjectProvider')]
    public function testAuthenticityMismatchPermanentlyRejectsProof(
        callable $storedFactory,
        string $expectedErrorCode,
    ): void {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $intent = $this->intent($proofs, $request);
        $inspector->put($storedFactory($intent->proof));

        $failure = $this->captureProofFailure(fn () => $this->confirm($proofs, $intent->proof, $request, $this->at('14:05')));
        self::assertSame($expectedErrorCode, $failure->errorCode);
        $stored = $repository->findProof((int) $intent->proof->id);
        self::assertSame(WithdrawalProofStatus::Rejected, $stored?->status);
        self::assertSame($expectedErrorCode, $stored?->verificationErrorCode);
        self::assertNull($stored?->checksum);
        self::assertNotNull($stored?->verificationAttemptedAt);
        self::assertNull($stored?->verifiedAt);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_rejected'"));

        $replay = $this->captureProofFailure(fn () => $this->confirm($proofs, $intent->proof, $request, $this->at('14:06')));
        self::assertSame($expectedErrorCode, $replay->errorCode);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_rejected'"));
    }

    /**
     * @return iterable<string, array{callable(WithdrawalProof): StoredObjectInspection, string}>
     */
    public static function invalidStoredObjectProvider(): iterable
    {
        $validChecksum = 'sha256:' . hash('sha256', 'valid');
        yield 'MIME mismatch' => [
            static fn (WithdrawalProof $proof): StoredObjectInspection => new StoredObjectInspection(
                $proof->objectKey, 'image/png', $proof->byteSize, 1, 1, null, $validChecksum, '%PDF-',
            ),
            'withdrawal_proof_object_mismatch',
        ];
        yield 'size mismatch' => [
            static fn (WithdrawalProof $proof): StoredObjectInspection => new StoredObjectInspection(
                $proof->objectKey, $proof->contentType, $proof->byteSize + 1, 1, 1, null, $validChecksum, '%PDF-',
            ),
            'withdrawal_proof_object_mismatch',
        ];
        yield 'missing checksum' => [
            static fn (WithdrawalProof $proof): StoredObjectInspection => new StoredObjectInspection(
                $proof->objectKey, $proof->contentType, $proof->byteSize, 1, 1, null, null, '%PDF-',
            ),
            'withdrawal_proof_checksum_invalid',
        ];
        yield 'untrusted checksum format' => [
            static fn (WithdrawalProof $proof): StoredObjectInspection => new StoredObjectInspection(
                $proof->objectKey, $proof->contentType, $proof->byteSize, 1, 1, null, 'etag:client-value', '%PDF-',
            ),
            'withdrawal_proof_checksum_invalid',
        ];
        yield 'magic mismatch' => [
            static fn (WithdrawalProof $proof): StoredObjectInspection => new StoredObjectInspection(
                $proof->objectKey, $proof->contentType, $proof->byteSize, 1, 1, null, $validChecksum, '<script>',
            ),
            'withdrawal_proof_magic_mismatch',
        ];
    }

    public function testInspectorCannotSubstituteAStoredObjectKey(): void
    {
        [, $repository, $request] = $this->approvedWithdrawal();
        $intent = $this->intent($this->proofService($repository, new InMemoryObjectStorageInspector()), $request);
        $proof = $intent->proof;
        $inspection = new StoredObjectInspection(
            $proof->objectKey . '.other',
            $proof->contentType,
            $proof->byteSize,
            1,
            1,
            null,
            'sha256:' . hash('sha256', 'valid'),
            '%PDF-',
        );
        $inspector = new class ($inspection) implements ObjectStorageInspectorInterface {
            public function __construct(private readonly StoredObjectInspection $inspection)
            {
            }

            public function inspect(string $objectKey): ?StoredObjectInspection
            {
                return $this->inspection;
            }
        };

        $failure = $this->captureProofFailure(
            fn () => $this->confirm($this->proofService($repository, $inspector), $proof, $request, $this->at('14:05')),
        );

        self::assertSame('withdrawal_proof_object_mismatch', $failure->errorCode);
        self::assertSame(WithdrawalProofStatus::Rejected, $repository->findProof((int) $proof->id)?->status);
    }

    #[DataProvider('scopeChangeInspectionProvider')]
    public function testVerificationFailsClosedWhenRequestScopeChangesDuringInspection(
        string $inspectionOutcome,
    ): void {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $intent = $this->intent(
            $this->proofService($repository, new InMemoryObjectStorageInspector()),
            $request,
        );
        $validBody = "%PDF-1.7\nvalid receipt";
        $inspection = match ($inspectionOutcome) {
            'valid' => $this->stored(
                $intent->proof,
                'sha256:' . hash('sha256', $validBody),
                $validBody,
            ),
            'invalid' => $this->stored(
                $intent->proof,
                'sha256:' . hash('sha256', '<html>'),
                '<html>',
            ),
            'missing' => null,
        };
        $inspector = $this->callbackInspector(
            function (string $objectKey) use ($connection, $request, $intent, $inspection): ?StoredObjectInspection {
                self::assertSame($intent->proof->objectKey, $objectKey);
                $connection->update(
                    'withdrawal_requests',
                    ['organization_id' => 84],
                    ['id' => (int) $request->id],
                );

                return $inspection;
            },
        );

        $failure = $this->captureProofFailure(
            fn () => $this->confirm(
                $this->proofService($repository, $inspector),
                $intent->proof,
                $request,
                $this->at('14:05'),
            ),
        );

        self::assertSame('withdrawal_proof_not_found', $failure->errorCode);
        self::assertSame(404, $failure->status);
        self::assertSame(
            WithdrawalProofStatus::PendingUpload,
            $repository->findProof((int) $intent->proof->id)?->status,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function scopeChangeInspectionProvider(): iterable
    {
        yield 'before verification commit' => ['valid'];
        yield 'before rejection commit' => ['invalid'];
        yield 'before deferred missing-object audit' => ['missing'];
    }

    #[DataProvider('staleInspectionProvider')]
    public function testConcurrentVerificationWinsOverStaleInspection(bool $inspectionMatchesMagic): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $intent = $this->intent(
            $this->proofService($repository, new InMemoryObjectStorageInspector()),
            $request,
        );
        $body = "%PDF-1.7\nauthoritative receipt";
        $checksum = 'sha256:' . hash('sha256', $body);
        $authoritativeInspection = $this->stored($intent->proof, $checksum, $body);
        $authoritativeInspector = new InMemoryObjectStorageInspector([$authoritativeInspection]);
        $concurrentProofs = $this->proofService($repository, $authoritativeInspector);
        $staleInspection = $this->stored(
            $intent->proof,
            $checksum,
            $inspectionMatchesMagic ? $body : '<html>',
        );
        $racingInspector = $this->callbackInspector(
            function (string $objectKey) use ($concurrentProofs, $intent, $request, $staleInspection): StoredObjectInspection {
                self::assertSame($intent->proof->objectKey, $objectKey);
                $winner = $this->confirm(
                    $concurrentProofs,
                    $intent->proof,
                    $request,
                    $this->at('14:04'),
                );
                self::assertSame(WithdrawalProofStatus::Verified, $winner->status);

                return $staleInspection;
            },
        );

        $confirmed = $this->confirm(
            $this->proofService($repository, $racingInspector),
            $intent->proof,
            $request,
            $this->at('14:05'),
        );

        self::assertSame(WithdrawalProofStatus::Verified, $confirmed->status);
        self::assertSame($checksum, $confirmed->checksum);
        self::assertSame('2026-07-10 14:04:00', $confirmed->verifiedAt?->format('Y-m-d H:i:s'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_verified'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_rejected'"));
    }

    /** @return iterable<string, array{bool}> */
    public static function staleInspectionProvider(): iterable
    {
        yield 'matching inspection' => [true];
        yield 'stale invalid inspection' => [false];
    }

    public function testConcurrentRejectionIsNotOverwrittenByAStaleValidInspection(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $intent = $this->intent(
            $this->proofService($repository, new InMemoryObjectStorageInspector()),
            $request,
        );
        $body = "%PDF-1.7\nstale valid receipt";
        $checksum = 'sha256:' . hash('sha256', $body);
        $rejectingInspection = $this->stored(
            $intent->proof,
            'sha256:' . hash('sha256', '<html>'),
            '<html>',
        );
        $rejectingInspector = new InMemoryObjectStorageInspector([$rejectingInspection]);
        $rejectingProofs = $this->proofService($repository, $rejectingInspector);
        $staleValidInspection = $this->stored($intent->proof, $checksum, $body);
        $racingInspector = $this->callbackInspector(
            function (string $objectKey) use ($rejectingProofs, $intent, $request, $staleValidInspection): StoredObjectInspection {
                self::assertSame($intent->proof->objectKey, $objectKey);
                $rejection = $this->captureProofFailure(
                    fn () => $this->confirm(
                        $rejectingProofs,
                        $intent->proof,
                        $request,
                        $this->at('14:04'),
                    ),
                );
                self::assertSame('withdrawal_proof_magic_mismatch', $rejection->errorCode);

                return $staleValidInspection;
            },
        );

        $failure = $this->captureProofFailure(
            fn () => $this->confirm(
                $this->proofService($repository, $racingInspector),
                $intent->proof,
                $request,
                $this->at('14:05'),
            ),
        );

        self::assertSame('withdrawal_proof_magic_mismatch', $failure->errorCode);
        self::assertSame(WithdrawalProofStatus::Rejected, $repository->findProof((int) $intent->proof->id)?->status);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_rejected'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'proof_verified'"));
    }

    public function testProofRemovedDuringInvalidInspectionIsReportedAsNotFound(): void
    {
        [$connection, $repository, $request] = $this->approvedWithdrawal();
        $intent = $this->intent(
            $this->proofService($repository, new InMemoryObjectStorageInspector()),
            $request,
        );
        $invalidInspection = $this->stored(
            $intent->proof,
            'sha256:' . hash('sha256', '<html>'),
            '<html>',
        );
        $removingInspector = $this->callbackInspector(
            function (string $objectKey) use ($connection, $intent, $invalidInspection): StoredObjectInspection {
                self::assertSame($intent->proof->objectKey, $objectKey);
                $connection->delete('withdrawal_audit_events', ['proof_id' => (int) $intent->proof->id]);
                self::assertSame(1, $connection->delete('withdrawal_proofs', ['id' => (int) $intent->proof->id]));

                return $invalidInspection;
            },
        );

        $failure = $this->captureProofFailure(
            fn () => $this->confirm(
                $this->proofService($repository, $removingInspector),
                $intent->proof,
                $request,
                $this->at('14:05'),
            ),
        );

        self::assertSame('withdrawal_proof_not_found', $failure->errorCode);
        self::assertSame(404, $failure->status);
        self::assertNull($repository->findProof((int) $intent->proof->id));
    }

    public function testProofRequestScopeAllowsFinanceHandoffAndRequiresApprovedWithdrawal(): void
    {
        [, $repository, $approved] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $intent = $this->intent($proofs, $approved);

        $ledgerRepository = new PointsLedgerRepository($this->connectionFromRepository($repository));
        $withdrawals = new WithdrawalService($repository, new PointsLedgerService($ledgerRepository), $ledgerRepository);
        $pending = $withdrawals->requestWithdrawal(42, 7, 100, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:proof:pending', $this->at('15:00'));
        $notApproved = $this->captureProofFailure(fn () => $this->intent($proofs, $pending));
        self::assertSame('withdrawal_proof_not_allowed', $notApproved->errorCode);

        $otherApproved = $withdrawals->approve((int) $pending->id, 99, null, $this->at('15:05'));
        $crossRequest = $this->captureProofFailure(
            fn () => $proofs->confirmUploadedProof((int) $intent->proof->id, (int) $otherApproved->id, 100, $this->at('15:06')),
        );
        self::assertSame('withdrawal_proof_not_found', $crossRequest->errorCode);
        self::assertSame(404, $crossRequest->status);

        $body = "%PDF-1.7\nfinance handoff";
        $inspector->put($this->stored($intent->proof, 'sha256:' . hash('sha256', $body), $body));
        $verified = $proofs->confirmUploadedProof(
            (int) $intent->proof->id,
            (int) $approved->id,
            100,
            $this->at('15:10'),
        );
        self::assertSame(WithdrawalProofStatus::Verified, $verified->status);
    }

    public function testPaidWithdrawalRejectsNewOrLateProofProcessing(): void
    {
        [$connection, $repository, $approved] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $verifiedIntent = $this->intent($proofs, $approved);
        $lateIntent = $proofs->createUploadIntent(
            (int) $approved->id,
            99,
            'late-receipt.pdf',
            'application/pdf',
            1_024,
            $this->at('14:01'),
        );

        $body = "%PDF-1.7\npaid receipt";
        $inspector->put($this->stored($verifiedIntent->proof, 'sha256:' . hash('sha256', $body), $body));
        $verified = $this->confirm($proofs, $verifiedIntent->proof, $approved, $this->at('14:05'));

        $ledgerRepository = new PointsLedgerRepository($connection);
        $withdrawals = new WithdrawalService($repository, new PointsLedgerService($ledgerRepository), $ledgerRepository);
        $paid = $withdrawals->markPaid(
            (int) $approved->id,
            100,
            (int) $verified->id,
            'Bank transfer reference confirmed.',
            $this->at('14:10'),
        );
        self::assertSame('paid', $paid->paymentStatus->value);

        $replayed = $proofs->confirmUploadedProof(
            (int) $verified->id,
            (int) $approved->id,
            100,
            $this->at('14:10'),
        );
        self::assertSame(WithdrawalProofStatus::Verified, $replayed->status);

        $newIntent = $this->captureProofFailure(
            fn () => $proofs->createUploadIntent(
                (int) $approved->id,
                99,
                'after-payment.pdf',
                'application/pdf',
                1_024,
                $this->at('14:11'),
            ),
        );
        self::assertSame('withdrawal_proof_not_allowed', $newIntent->errorCode);

        $lateConfirmation = $this->captureProofFailure(
            fn () => $this->confirm($proofs, $lateIntent->proof, $approved, $this->at('14:12')),
        );
        self::assertSame('withdrawal_proof_not_allowed', $lateConfirmation->errorCode);
        self::assertSame(
            WithdrawalProofStatus::PendingUpload,
            $repository->findProof((int) $lateIntent->proof->id)?->status,
        );
    }

    public function testConcurrentPaymentCannotVerifyASecondProofAfterStorageInspection(): void
    {
        [$connection, $repository, $approved] = $this->approvedWithdrawal();
        $inspector = new InMemoryObjectStorageInspector();
        $proofs = $this->proofService($repository, $inspector);
        $paymentIntent = $this->intent($proofs, $approved);
        $lateIntent = $proofs->createUploadIntent(
            (int) $approved->id,
            99,
            'concurrent-receipt.pdf',
            'application/pdf',
            1_024,
            $this->at('14:01'),
        );

        $paymentBody = "%PDF-1.7\npayment receipt";
        $inspector->put($this->stored(
            $paymentIntent->proof,
            'sha256:' . hash('sha256', $paymentBody),
            $paymentBody,
        ));
        $verifiedPaymentProof = $this->confirm($proofs, $paymentIntent->proof, $approved, $this->at('14:05'));

        $ledgerRepository = new PointsLedgerRepository($connection);
        $withdrawals = new WithdrawalService($repository, new PointsLedgerService($ledgerRepository), $ledgerRepository);
        $lateBody = "%PDF-1.7\nlate receipt";
        $lateStored = $this->stored(
            $lateIntent->proof,
            'sha256:' . hash('sha256', $lateBody),
            $lateBody,
        );
        $racingInspector = new class($withdrawals, $approved, $verifiedPaymentProof, $lateStored) implements ObjectStorageInspectorInterface {
            public function __construct(
                private readonly WithdrawalService $withdrawals,
                private readonly WithdrawalRequest $withdrawal,
                private readonly WithdrawalProof $paymentProof,
                private readonly StoredObjectInspection $stored,
            ) {
            }

            public function inspect(string $objectKey): ?StoredObjectInspection
            {
                $this->withdrawals->markPaid(
                    (int) $this->withdrawal->id,
                    100,
                    (int) $this->paymentProof->id,
                    'Payment completed while object inspection was running.',
                    new DateTimeImmutable('2026-06-08 14:10:00'),
                );

                return $this->stored;
            }
        };

        $failure = $this->captureProofFailure(
            fn () => $this->confirm(
                $this->proofService($repository, $racingInspector),
                $lateIntent->proof,
                $approved,
                $this->at('14:11'),
            ),
        );

        self::assertSame('withdrawal_proof_not_allowed', $failure->errorCode);
        self::assertSame(
            WithdrawalProofStatus::PendingUpload,
            $repository->findProof((int) $lateIntent->proof->id)?->status,
        );
    }

    public function testListsProofHistoryNewestFirstWithStrictBounds(): void
    {
        [, $repository, $approved] = $this->approvedWithdrawal();
        $proofs = $this->proofService($repository, new InMemoryObjectStorageInspector());
        $first = $this->intent($proofs, $approved);
        $second = $proofs->createUploadIntent(
            (int) $approved->id,
            99,
            'newer-receipt.pdf',
            'application/pdf',
            2_048,
            $this->at('14:01'),
        );

        $listed = $proofs->listProofs((int) $approved->id, 100);
        self::assertSame([(int) $second->proof->id, (int) $first->proof->id], array_map(
            static fn (WithdrawalProof $proof): int => (int) $proof->id,
            $listed,
        ));
        self::assertSame([(int) $second->proof->id], array_map(
            static fn (WithdrawalProof $proof): int => (int) $proof->id,
            $proofs->listProofs((int) $approved->id, 1),
        ));

        foreach ([[0, 1], [(int) $approved->id, 0], [(int) $approved->id, 101]] as [$withdrawalId, $limit]) {
            try {
                $proofs->listProofs($withdrawalId, $limit);
                self::fail('Expected invalid proof-list bounds.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $missing = $this->captureProofFailure(fn () => $proofs->listProofs(999, 50));
        self::assertSame('withdrawal_not_found', $missing->errorCode);
    }

    #[DataProvider('invalidUploadProvider')]
    public function testUploadIntentValidatesFilePolicy(
        string $filename,
        string $contentType,
        int $byteSize,
        string $expectedCode,
    ): void {
        [, $repository, $request] = $this->approvedWithdrawal();
        $proofs = $this->proofService($repository, new InMemoryObjectStorageInspector());
        $failure = $this->captureProofFailure(
            fn () => $proofs->createUploadIntent((int) $request->id, 99, $filename, $contentType, $byteSize, $this->at('14:00')),
        );
        self::assertSame($expectedCode, $failure->errorCode);
        self::assertSame(422, $failure->status);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function invalidUploadProvider(): iterable
    {
        yield 'unsupported extension' => ['receipt.exe', 'application/octet-stream', 100, 'withdrawal_proof_type_not_allowed'];
        yield 'extension and MIME mismatch' => ['receipt.pdf', 'image/png', 100, 'withdrawal_proof_type_not_allowed'];
        yield 'empty size' => ['receipt.pdf', 'application/pdf', 0, 'withdrawal_proof_size_out_of_bounds'];
        yield 'too large' => ['receipt.pdf', 'application/pdf', WithdrawalProofPolicy::DEFAULT_MAX_BYTES + 1, 'withdrawal_proof_size_out_of_bounds'];
    }

    public function testInvalidIdentityProofIdTokenAndSignerAreRejected(): void
    {
        [, $repository, $request] = $this->approvedWithdrawal();
        $proofs = $this->proofService($repository, new InMemoryObjectStorageInspector());
        $invalidIdentity = $this->captureInvalidArgument(
            fn () => $proofs->createUploadIntent(0, 99, 'receipt.pdf', 'application/pdf', 100, $this->at('14:00')),
        );
        self::assertSame('Withdrawal proof metadata is invalid.', $invalidIdentity->getMessage());

        $invalidId = $this->captureInvalidArgument(
            fn () => $proofs->confirmUploadedProof(0, (int) $request->id, 99, $this->at('14:00')),
        );
        self::assertSame('proof_id must be a positive integer.', $invalidId->getMessage());

        $invalidToken = new WithdrawalProofService(
            $repository,
            $this->signer(),
            static fn (): string => '../escape',
            new InMemoryObjectStorageInspector(),
        );
        $tokenFailure = $this->captureRuntime(
            fn () => $invalidToken->createUploadIntent((int) $request->id, 99, 'receipt.pdf', 'application/pdf', 100, $this->at('14:00')),
        );
        self::assertSame('withdrawal_proof_token_invalid', $tokenFailure->getMessage());

        $badSigner = new WithdrawalProofService(
            $repository,
            new class implements \VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface {
                public function presignPut(\VertoAD\Infrastructure\Storage\PresignedUploadRequest $request): \VertoAD\Infrastructure\Storage\PresignedUpload
                {
                    return new \VertoAD\Infrastructure\Storage\PresignedUpload('https://example.test', 'PUT', 'other-key', 200, []);
                }
            },
            static fn (): string => 'proof-token',
            new InMemoryObjectStorageInspector(),
        );
        $signerFailure = $this->captureRuntime(
            fn () => $badSigner->createUploadIntent((int) $request->id, 99, 'receipt.pdf', 'application/pdf', 100, $this->at('14:00')),
        );
        self::assertSame('withdrawal_proof_signer_mismatch', $signerFailure->getMessage());
        self::assertNull($repository->findProof(1));
    }

    /**
     * @return array{Connection, WithdrawalRepository, WithdrawalRequest}
     */
    private function approvedWithdrawal(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 2_000, 'withdrawal:proof:fixture');
        $repository = new WithdrawalRepository($connection);
        $withdrawals = new WithdrawalService($repository, $ledger, $ledgerRepository);
        $request = $withdrawals->requestWithdrawal(42, 7, 1_000, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:proof:request', $this->at('12:00'));
        $approved = $withdrawals->approve((int) $request->id, 99, null, $this->at('13:00'));

        return [$connection, $repository, $approved];
    }

    private function proofService(
        WithdrawalRepository $repository,
        ?ObjectStorageInspectorInterface $inspector,
    ): WithdrawalProofService {
        return new WithdrawalProofService(
            $repository,
            $this->signer(),
            static fn (): string => 'proof-token',
            $inspector,
        );
    }

    /** @param callable(string): ?StoredObjectInspection $operation */
    private function callbackInspector(callable $operation): ObjectStorageInspectorInterface
    {
        return new class($operation) implements ObjectStorageInspectorInterface {
            public function __construct(private readonly mixed $operation)
            {
            }

            public function inspect(string $objectKey): ?StoredObjectInspection
            {
                $operation = $this->operation;

                return $operation($objectKey);
            }
        };
    }

    private function signer(): DeterministicPresignedUploadSigner
    {
        return new DeterministicPresignedUploadSigner([
            'endpoint' => 'https://r2.example.test',
            'bucket' => 'withdrawal-proofs',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => true,
        ]);
    }

    private function intent(WithdrawalProofService $service, WithdrawalRequest $request): \VertoAD\Domain\Billing\WithdrawalProofUploadIntent
    {
        return $service->createUploadIntent(
            (int) $request->id,
            99,
            'receipt.pdf',
            'application/pdf',
            1_024,
            $this->at('14:00'),
        );
    }

    private function confirm(
        WithdrawalProofService $service,
        WithdrawalProof $proof,
        WithdrawalRequest $request,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        return $service->confirmUploadedProof(
            (int) $proof->id,
            (int) $request->id,
            $proof->uploadedByUserId,
            $now,
        );
    }

    private function stored(WithdrawalProof $proof, ?string $checksum, string $leadingBytes): StoredObjectInspection
    {
        return new StoredObjectInspection(
            $proof->objectKey,
            $proof->contentType,
            $proof->byteSize,
            1,
            1,
            null,
            $checksum,
            $leadingBytes,
        );
    }

    private function connectionFromRepository(WithdrawalRepository $repository): Connection
    {
        $property = new \ReflectionProperty($repository, 'connection');

        return $property->getValue($repository);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-10 ' . $time . ':00 UTC');
    }

    private function captureProofFailure(callable $operation): WithdrawalProofValidationException
    {
        try {
            $operation();
        } catch (WithdrawalProofValidationException $exception) {
            return $exception;
        }

        self::fail('Expected withdrawal proof validation exception.');
    }

    private function captureInvalidArgument(callable $operation): InvalidArgumentException
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            return $exception;
        }

        self::fail('Expected invalid argument exception.');
    }

    private function captureRuntime(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        self::fail('Expected runtime exception.');
    }
}
