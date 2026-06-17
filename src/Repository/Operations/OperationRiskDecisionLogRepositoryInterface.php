<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\OperationRiskDecisionLog;

interface OperationRiskDecisionLogRepositoryInterface
{
    public function append(OperationRiskDecisionLog $entry): OperationRiskDecisionLog;

    public function find(string $decisionId): ?OperationRiskDecisionLog;

    /**
     * @param array<string, mixed> $filters
     * @return list<OperationRiskDecisionLog>
     */
    public function search(array $filters = []): array;
}
