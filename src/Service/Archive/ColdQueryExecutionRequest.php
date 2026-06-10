<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class ColdQueryExecutionRequest
{
    /**
     * @param list<mixed> $parameters
     * @param list<string> $objectKeys
     */
    public function __construct(
        public string $jobId,
        public string $sql,
        public array $parameters,
        public array $objectKeys,
        public string $resultObjectKey,
        public string $currentStatus,
    ) {
    }
}
