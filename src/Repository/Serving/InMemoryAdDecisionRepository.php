<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
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

    public function searchDecisions(array $filters): array
    {
        $items = array_values(array_filter(
            $this->decisions,
            function (AdDecision $decision) use ($filters): bool {
                if (isset($filters['request_id']) && (string) $filters['request_id'] !== (string) ($decision->requestId ?? '')) {
                    return false;
                }
                if (isset($filters['ip_address']) && (string) $filters['ip_address'] !== (string) ($decision->ipAddress ?? '')) {
                    return false;
                }
                if (isset($filters['occurred_from']) && $decision->decidedAt < new DateTimeImmutable((string) $filters['occurred_from'])) {
                    return false;
                }
                if (isset($filters['occurred_to']) && $decision->decidedAt > new DateTimeImmutable((string) $filters['occurred_to'])) {
                    return false;
                }

                return true;
            },
        ));

        if (isset($filters['limit']) && is_int($filters['limit']) && $filters['limit'] > 0) {
            return array_slice($items, 0, $filters['limit']);
        }

        return $items;
    }
}
