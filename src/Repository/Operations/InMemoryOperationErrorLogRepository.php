<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\OperationErrorLog;

final class InMemoryOperationErrorLogRepository implements OperationErrorLogRepositoryInterface
{
    /** @var array<string, OperationErrorLog> */
    private array $entries = [];

    public function append(OperationErrorLog $entry): OperationErrorLog
    {
        $this->entries[$entry->error_id] = $entry;

        return $entry;
    }

    public function find(string $errorId): ?OperationErrorLog
    {
        return $this->entries[$errorId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->entries);
    }
}
