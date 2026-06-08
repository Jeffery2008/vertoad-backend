<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use VertoAD\Domain\Serving\AdDecision;

final class InMemoryAdDecisionRepository implements AdDecisionRepositoryInterface
{
    /** @var array<string, AdDecision> */
    private array $decisions = [];

    public function save(AdDecision $decision): void
    {
        $this->decisions[$decision->decisionId] = $decision;
    }

    public function find(string $decisionId): ?AdDecision
    {
        return $this->decisions[$decisionId] ?? null;
    }
}
