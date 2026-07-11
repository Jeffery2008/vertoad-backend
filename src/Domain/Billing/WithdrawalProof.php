<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;

final readonly class WithdrawalProof
{
    public function __construct(
        public ?int $id,
        public int $withdrawalRequestId,
        public int $organizationId,
        public int $uploadedByUserId,
        public string $objectKey,
        public string $contentType,
        public int $byteSize,
        public ?string $checksum,
        public WithdrawalProofStatus $status,
        public ?string $verificationErrorCode,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $verificationAttemptedAt,
        public ?DateTimeImmutable $verifiedAt,
    ) {
    }
}
