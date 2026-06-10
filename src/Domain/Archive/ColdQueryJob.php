<?php

declare(strict_types=1);

namespace VertoAD\Domain\Archive;

use DateTimeImmutable;

final readonly class ColdQueryJob
{
    /**
     * @param list<mixed> $parameters
     * @param list<string> $scannedObjectKeys
     */
    public function __construct(
        public string $jobId,
        public string $status,
        public string $sql,
        public array $parameters,
        public string $requestedBy,
        public string $resultFormat,
        public int $rowCount,
        public ?string $resultObjectKey,
        public array $scannedObjectKeys,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public ?string $errorMessage = null,
    ) {
    }
}
