<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

final readonly class BackupExecutionResult
{
    public function __construct(
        public string $jobType,
        public ?string $jobId,
        public string $status,
        public int $objectCount = 0,
        public int $byteCount = 0,
        public ?string $errorMessage = null,
    ) {
    }
}
