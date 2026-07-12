<?php

declare(strict_types=1);

namespace VertoAD\Domain\Operations;

use DateTimeImmutable;

final readonly class BackupJob
{
    public function __construct(
        public string $jobId,
        public string $jobType,
        public ?string $sourceBackupId,
        public string $status,
        public int $requestedByUserId,
        public string $requestId,
        public string $environment,
        public ?string $reason,
        public ?string $manifestObjectKey,
        public ?string $manifestSha256,
        public ?string $mysqlObjectKey,
        public ?string $mysqlSha256,
        public ?string $configObjectKey,
        public ?string $evidenceObjectKey,
        public int $objectCount,
        public int $byteCount,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?string $leaseOwner = null,
        public ?DateTimeImmutable $leaseExpiresAt = null,
        public int $attemptCount = 0,
        public ?DateTimeImmutable $heartbeatAt = null,
    ) {
    }
}
