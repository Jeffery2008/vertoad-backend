<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use VertoAD\Domain\Serving\AdDecision;

interface AdDecisionRepositoryInterface
{
    public function save(AdDecision $decision): void;

    public function find(string $decisionId): ?AdDecision;

    /**
     * @param array{
     *     request_id?: string|null,
     *     ip_address?: string|null,
     *     occurred_from?: string|null,
     *     occurred_to?: string|null,
     *     limit?: int|null
     * } $filters
     * @return list<AdDecision>
     */
    public function searchDecisions(array $filters): array;
}
