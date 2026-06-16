<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\OperationErrorLog;

interface OperationErrorLogRepositoryInterface
{
    public function append(OperationErrorLog $entry): OperationErrorLog;

    public function find(string $errorId): ?OperationErrorLog;

    /**
     * @return list<OperationErrorLog>
     */
    public function all(?string $requestId = null): array;
}
