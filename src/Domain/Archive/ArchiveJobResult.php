<?php

declare(strict_types=1);

namespace VertoAD\Domain\Archive;

final readonly class ArchiveJobResult
{
    /**
     * @param array<string, int|string> $metrics
     */
    public function __construct(
        public string $jobName,
        public string $status,
        public array $metrics,
    ) {
    }
}
