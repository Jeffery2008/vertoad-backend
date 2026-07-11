<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalPaymentStatus;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalProofPolicy;
use VertoAD\Domain\Billing\WithdrawalProofStatus;
use VertoAD\Domain\Billing\WithdrawalProofUploadIntent;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Domain\Billing\WithdrawalReviewStatus;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Billing\WithdrawalRepository;

final class WithdrawalProofService
{
    private const int UPLOAD_TTL_MINUTES = 15;

    /** @param callable(): string $tokenFactory */
    public function __construct(
        private readonly WithdrawalRepository $repository,
        private readonly ObjectStorageUploadSignerInterface $signer,
        private readonly mixed $tokenFactory,
        private readonly ?ObjectStorageInspectorInterface $inspector = null,
        private readonly ?WithdrawalProofPolicy $policy = null,
    ) {
    }

    public function createUploadIntent(
        int $withdrawalRequestId,
        int $uploadedByUserId,
        string $filename,
        string $contentType,
        int $byteSize,
        DateTimeImmutable $now,
    ): WithdrawalProofUploadIntent {
        $this->validateIdentity($withdrawalRequestId, $uploadedByUserId);
        [$safeFilename, $contentType] = $this->validateUploadShape($filename, $contentType, $byteSize);
        $request = $this->requireReadyRequest($withdrawalRequestId);
        $tokenFactory = $this->tokenFactory;
        $token = trim((string) $tokenFactory());
        if (preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $token) !== 1) {
            throw new RuntimeException('withdrawal_proof_token_invalid');
        }

        $objectKey = sprintf(
            'withdrawals/%d/%d/%s-%s',
            $request->organizationId,
            $withdrawalRequestId,
            $token,
            $safeFilename,
        );
        $upload = $this->signer->presignPut(new PresignedUploadRequest(
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            expiresAt: $now->add(new DateInterval('PT' . self::UPLOAD_TTL_MINUTES . 'M')),
        ));
        if ($upload->objectKey !== $objectKey) {
            throw new RuntimeException('withdrawal_proof_signer_mismatch');
        }

        $proof = $this->repository->transactional(function () use (
            $withdrawalRequestId,
            $request,
            $uploadedByUserId,
            $objectKey,
            $contentType,
            $byteSize,
            $now,
        ): WithdrawalProof {
            $lockedRequest = $this->requireReadyRequest($withdrawalRequestId, forUpdate: true);
            if ($lockedRequest->organizationId !== $request->organizationId) {
                throw new WithdrawalProofValidationException(
                    'withdrawal_not_found',
                    'Withdrawal request was not found.',
                    404,
                );
            }

            $proof = $this->repository->createProof(
                withdrawalRequestId: $withdrawalRequestId,
                organizationId: $lockedRequest->organizationId,
                uploadedByUserId: $uploadedByUserId,
                objectKey: $objectKey,
                contentType: $contentType,
                byteSize: $byteSize,
                now: $now,
            );
            $this->auditProof($lockedRequest, $proof, $uploadedByUserId, 'proof_upload_created', null, [
                'object_key' => $objectKey,
                'content_type' => $contentType,
                'byte_size' => $byteSize,
            ], $now);

            return $proof;
        });

        return new WithdrawalProofUploadIntent($proof, $upload);
    }

    public function confirmUploadedProof(
        int $proofId,
        int $withdrawalRequestId,
        int $actorUserId,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $this->validateIdentity($withdrawalRequestId, $actorUserId);
        if ($proofId <= 0) {
            throw new InvalidArgumentException('proof_id must be a positive integer.');
        }

        $request = $this->requireRequest($withdrawalRequestId);
        $proof = $this->repository->findProofForRequest($proofId, $withdrawalRequestId);
        if ($proof === null || $proof->organizationId !== $request->organizationId) {
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_not_found',
                'Withdrawal proof was not found in this request scope.',
                404,
            );
        }
        if ($proof->status === WithdrawalProofStatus::Verified) {
            return $proof;
        }
        if ($proof->status === WithdrawalProofStatus::Rejected) {
            throw new WithdrawalProofValidationException(
                $proof->verificationErrorCode ?? 'withdrawal_proof_verification_failed',
                'Withdrawal proof was previously rejected by server-side verification.',
            );
        }
        $this->assertReadyRequest($request);

        if ($this->inspector === null) {
            $this->auditProofWhileReady($request, $proof, $actorUserId, 'withdrawal_proof_verification_unavailable', $now);
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_verification_unavailable',
                'Object storage verification is unavailable.',
                503,
            );
        }

        try {
            $stored = $this->inspector->inspect($proof->objectKey);
        } catch (RuntimeException $exception) {
            $this->auditProofWhileReady($request, $proof, $actorUserId, 'withdrawal_proof_verification_unavailable', $now);
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_verification_unavailable',
                'Object storage verification is temporarily unavailable.',
                503,
            );
        }

        if ($stored === null) {
            $this->auditProofWhileReady(
                $request,
                $proof,
                $actorUserId,
                'withdrawal_proof_object_not_found',
                $now,
            );
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_object_not_found',
                'Uploaded withdrawal proof was not found in object storage.',
                404,
            );
        }

        $failure = $this->storedObjectFailure($proof, $stored);
        if ($failure !== null) {
            return $this->rejectVerification(
                $request,
                $proof,
                $actorUserId,
                $failure['code'],
                $failure['message'],
                $now,
            );
        }

        $checksum = strtolower(trim((string) $stored->checksum));

        return $this->repository->transactional(function () use (
            $request,
            $proof,
            $actorUserId,
            $checksum,
            $now,
        ): WithdrawalProof {
            $lockedRequest = $this->requireReadyRequest((int) $request->id, forUpdate: true);
            if ($lockedRequest->organizationId !== $proof->organizationId) {
                throw new WithdrawalProofValidationException(
                    'withdrawal_proof_not_found',
                    'Withdrawal proof was not found in this request scope.',
                    404,
                );
            }

            $verified = $this->repository->verifyProofIfPending((int) $proof->id, $checksum, $now);
            if ($verified === null) {
                $current = $this->repository->findProof((int) $proof->id);
                if ($current?->status === WithdrawalProofStatus::Verified) {
                    return $current;
                }

                throw new WithdrawalProofValidationException(
                    $current?->verificationErrorCode ?? 'withdrawal_proof_verification_failed',
                    'Withdrawal proof verification state changed concurrently.',
                );
            }

            $this->auditProof($lockedRequest, $verified, $actorUserId, 'proof_verified', null, [
                'checksum' => $checksum,
                'content_type' => $verified->contentType,
                'byte_size' => $verified->byteSize,
            ], $now);

            return $verified;
        });
    }

    /** @return list<WithdrawalProof> */
    public function listProofs(int $withdrawalRequestId, int $limit): array
    {
        if ($withdrawalRequestId <= 0) {
            throw new InvalidArgumentException('withdrawal_id must be a positive integer.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        $this->requireRequest($withdrawalRequestId);

        return $this->repository->listProofsForRequest($withdrawalRequestId, $limit);
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function validateUploadShape(string $filename, string $contentType, int $byteSize): array
    {
        $filename = trim($filename);
        $contentType = strtolower(trim($contentType));
        $basename = preg_replace('~^.*[\\\\/]~', '', $filename) ?? '';
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?: '';
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
        $expectedContentType = $this->uploadPolicy()->contentTypeForExtension($extension);

        if ($safeFilename === '' || $expectedContentType === null || $contentType !== $expectedContentType) {
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_type_not_allowed',
                'Withdrawal proof must be a PDF, PNG, or JPEG with matching extension and content type.',
                422,
            );
        }
        if ($byteSize <= 0 || $byteSize > $this->uploadPolicy()->maxBytes) {
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_size_out_of_bounds',
                'Withdrawal proof byte size is outside allowed bounds.',
                422,
            );
        }

        return [$safeFilename, $contentType];
    }

    private function validateIdentity(int $withdrawalRequestId, int $actorUserId): void
    {
        if ($withdrawalRequestId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('Withdrawal proof metadata is invalid.');
        }
    }

    private function requireReadyRequest(int $withdrawalRequestId, bool $forUpdate = false): WithdrawalRequest
    {
        $request = $this->requireRequest($withdrawalRequestId, $forUpdate);
        $this->assertReadyRequest($request);

        return $request;
    }

    private function requireRequest(int $withdrawalRequestId, bool $forUpdate = false): WithdrawalRequest
    {
        $request = $forUpdate
            ? $this->repository->findRequestForUpdate($withdrawalRequestId)
            : $this->repository->findRequest($withdrawalRequestId);
        if ($request === null) {
            throw new WithdrawalProofValidationException(
                'withdrawal_not_found',
                'Withdrawal request was not found.',
                404,
            );
        }

        return $request;
    }

    private function assertReadyRequest(WithdrawalRequest $request): void
    {
        if (
            $request->reviewStatus !== WithdrawalReviewStatus::Approved
            || $request->paymentStatus !== WithdrawalPaymentStatus::Pending
        ) {
            throw new WithdrawalProofValidationException(
                'withdrawal_proof_not_allowed',
                'Payment-proof processing is allowed only after withdrawal approval and before payment completion.',
            );
        }
    }

    /** @return array{code:string, message:string}|null */
    private function storedObjectFailure(WithdrawalProof $proof, StoredObjectInspection $stored): ?array
    {
        if (
            $stored->objectKey !== $proof->objectKey
            || strtolower(trim($stored->contentType)) !== $proof->contentType
            || $stored->byteSize !== $proof->byteSize
        ) {
            return [
                'code' => 'withdrawal_proof_object_mismatch',
                'message' => 'Stored object metadata does not match the withdrawal proof upload intent.',
            ];
        }

        $checksum = strtolower(trim((string) $stored->checksum));
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $checksum) !== 1) {
            return [
                'code' => 'withdrawal_proof_checksum_invalid',
                'message' => 'Stored object did not provide a valid server-computed SHA-256 checksum.',
            ];
        }

        if (!$this->uploadPolicy()->matchesMagic($proof->contentType, $stored->leadingBytes)) {
            return [
                'code' => 'withdrawal_proof_magic_mismatch',
                'message' => 'Stored object bytes do not match the declared withdrawal proof content type.',
            ];
        }

        return null;
    }

    private function rejectVerification(
        WithdrawalRequest $request,
        WithdrawalProof $proof,
        int $actorUserId,
        string $errorCode,
        string $message,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $current = $this->repository->transactional(function () use (
            $request,
            $proof,
            $actorUserId,
            $errorCode,
            $now,
        ): WithdrawalProof {
            $lockedRequest = $this->requireReadyRequest((int) $request->id, forUpdate: true);
            if ($lockedRequest->organizationId !== $proof->organizationId) {
                throw new WithdrawalProofValidationException(
                    'withdrawal_proof_not_found',
                    'Withdrawal proof was not found in this request scope.',
                    404,
                );
            }

            $rejected = $this->repository->rejectProofIfPending((int) $proof->id, $errorCode, $now);
            if ($rejected === null) {
                $current = $this->repository->findProof((int) $proof->id);
                if ($current instanceof WithdrawalProof) {
                    return $current;
                }

                throw new WithdrawalProofValidationException(
                    'withdrawal_proof_not_found',
                    'Withdrawal proof disappeared during verification.',
                    404,
                );
            }

            $this->auditProof($lockedRequest, $rejected, $actorUserId, 'proof_rejected', $errorCode, null, $now);

            return $rejected;
        });

        if ($current->status === WithdrawalProofStatus::Verified) {
            return $current;
        }

        throw new WithdrawalProofValidationException($current->verificationErrorCode ?? $errorCode, $message);
    }

    private function auditProofWhileReady(
        WithdrawalRequest $request,
        WithdrawalProof $proof,
        int $actorUserId,
        string $errorCode,
        DateTimeImmutable $now,
    ): void {
        $this->repository->transactional(function () use ($request, $proof, $actorUserId, $errorCode, $now): void {
            $lockedRequest = $this->requireReadyRequest((int) $request->id, forUpdate: true);
            if ($lockedRequest->organizationId !== $proof->organizationId) {
                throw new WithdrawalProofValidationException(
                    'withdrawal_proof_not_found',
                    'Withdrawal proof was not found in this request scope.',
                    404,
                );
            }

            $this->auditProof(
                $lockedRequest,
                $proof,
                $actorUserId,
                'proof_verification_deferred',
                $errorCode,
                null,
                $now,
            );
        });
    }

    /** @param array<string, mixed>|null $metadata */
    private function auditProof(
        WithdrawalRequest $request,
        WithdrawalProof $proof,
        int $actorUserId,
        string $action,
        ?string $notes,
        ?array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->repository->appendAuditEvent(
            withdrawalRequestId: (int) $request->id,
            organizationId: $request->organizationId,
            actorUserId: $actorUserId,
            action: $action,
            fromReviewStatus: $request->reviewStatus,
            toReviewStatus: $request->reviewStatus,
            fromPaymentStatus: $request->paymentStatus,
            toPaymentStatus: $request->paymentStatus,
            proofId: $proof->id,
            notes: $notes,
            metadata: $metadata,
            now: $now,
        );
    }

    private function uploadPolicy(): WithdrawalProofPolicy
    {
        return $this->policy ?? new WithdrawalProofPolicy();
    }
}
