<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalProofUploadIntent;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Repository\Billing\WithdrawalRepository;

final class WithdrawalProofService
{
    /**
     * @param callable(): string $tokenFactory
     */
    public function __construct(
        private readonly WithdrawalRepository $repository,
        private readonly ObjectStorageUploadSignerInterface $signer,
        private readonly mixed $tokenFactory,
    ) {
    }

    public function createUploadIntent(
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $filename,
        string $contentType,
        int $byteSize,
        DateTimeImmutable $now,
    ): WithdrawalProofUploadIntent {
        $this->validateProofInput($withdrawalRequestId, $organizationId, $uploadedByUserId, $contentType, $byteSize);
        $request = $this->requireRequestForOrganization($withdrawalRequestId, $organizationId);
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?: 'proof';
        $tokenFactory = $this->tokenFactory;
        $objectKey = sprintf(
            'withdrawals/%d/%d/%s-%s',
            $request->organizationId,
            $withdrawalRequestId,
            trim((string) $tokenFactory()),
            $safeFilename,
        );

        $proof = $this->repository->createProof(
            withdrawalRequestId: $withdrawalRequestId,
            organizationId: $request->organizationId,
            uploadedByUserId: $uploadedByUserId,
            objectKey: $objectKey,
            contentType: trim($contentType),
            byteSize: $byteSize,
            now: $now,
        );
        $upload = $this->signer->presignPut(new PresignedUploadRequest(
            objectKey: $proof->objectKey,
            contentType: $proof->contentType,
            byteSize: $proof->byteSize,
            expiresAt: $now->add(new DateInterval('PT15M')),
        ));

        return new WithdrawalProofUploadIntent($proof, $upload);
    }

    public function confirmUploadedProof(
        int $proofId,
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $objectKey,
        string $contentType,
        int $byteSize,
        string $checksum,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $this->validateProofInput($withdrawalRequestId, $organizationId, $uploadedByUserId, $contentType, $byteSize);
        if ($proofId <= 0 || trim($objectKey) === '' || trim($checksum) === '') {
            throw new InvalidArgumentException('Withdrawal proof confirmation is invalid.');
        }
        $this->requireRequestForOrganization($withdrawalRequestId, $organizationId);

        return $this->repository->confirmProof(
            proofId: $proofId,
            withdrawalRequestId: $withdrawalRequestId,
            organizationId: $organizationId,
            uploadedByUserId: $uploadedByUserId,
            objectKey: trim($objectKey),
            contentType: trim($contentType),
            byteSize: $byteSize,
            checksum: trim($checksum),
            now: $now,
        );
    }

    private function requireRequestForOrganization(int $withdrawalRequestId, int $organizationId): WithdrawalRequest
    {
        $request = $this->repository->findRequest($withdrawalRequestId);
        if ($request === null || $request->organizationId !== $organizationId) {
            throw new RuntimeException('withdrawal_not_found');
        }

        return $request;
    }

    private function validateProofInput(
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $contentType,
        int $byteSize,
    ): void {
        if ($withdrawalRequestId <= 0 || $organizationId <= 0 || $uploadedByUserId <= 0 || trim($contentType) === '' || $byteSize <= 0) {
            throw new InvalidArgumentException('Withdrawal proof metadata is invalid.');
        }
    }
}
