<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use VertoAD\Domain\Serving\AdDecision;

interface AdDecisionRepositoryInterface
{
    public function save(AdDecision $decision): void;

    public function find(string $decisionId): ?AdDecision;
}
