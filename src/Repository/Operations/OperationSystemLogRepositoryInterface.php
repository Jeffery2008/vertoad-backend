<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\OperationSystemLog;

interface OperationSystemLogRepositoryInterface
{
    public function append(OperationSystemLog $entry): OperationSystemLog;

    public function find(string $logId): ?OperationSystemLog;

    /**
     * @param array<string, mixed> $filters
     * @return list<OperationSystemLog>
     */
    public function search(array $filters = []): array;
}
