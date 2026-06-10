<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class ColdQueryExecutionResult
{
    /**
     * @param list<string> $scannedObjectKeys
     */
    public function __construct(
        public string $resultObjectKey,
        public string $resultFormat,
        public int $rowCount,
        public array $scannedObjectKeys,
    ) {
    }
}
