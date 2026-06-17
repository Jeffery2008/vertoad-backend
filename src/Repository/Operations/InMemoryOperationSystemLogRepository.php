<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use VertoAD\Domain\Operations\OperationSystemLog;

final class InMemoryOperationSystemLogRepository implements OperationSystemLogRepositoryInterface
{
    /** @var array<string, OperationSystemLog> */
    private array $entries = [];

    public function append(OperationSystemLog $entry): OperationSystemLog
    {
        $this->entries[$entry->log_id] = $entry;

        return $entry;
    }

    public function find(string $logId): ?OperationSystemLog
    {
        return $this->entries[trim($logId)] ?? null;
    }

    public function search(array $filters = []): array
    {
        $items = array_values(array_filter(
            $this->entries,
            fn (OperationSystemLog $entry): bool => $this->matches($entry, $filters),
        ));
        usort(
            $items,
            static fn (OperationSystemLog $left, OperationSystemLog $right): int =>
                strcmp($right->occurred_at->format(DATE_ATOM), $left->occurred_at->format(DATE_ATOM))
                    ?: strcmp($right->log_id, $left->log_id),
        );

        return array_slice($items, 0, $this->limit($filters['limit'] ?? null));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function matches(OperationSystemLog $entry, array $filters): bool
    {
        foreach (['request_id', 'ip_address'] as $field) {
            $expected = $this->nullableString($filters[$field] ?? null);
            if ($expected !== null && $entry->{$field} !== $expected) {
                return false;
            }
        }
        $endpoint = $this->endpointFilter($filters['endpoint'] ?? null);
        if ($endpoint['endpoint'] !== null && $entry->endpoint !== $endpoint['endpoint']) {
            return false;
        }
        if ($endpoint['method'] !== null && $entry->http_method !== $endpoint['method']) {
            return false;
        }

        return $this->withinRange($entry->occurred_at, $filters);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function withinRange(DateTimeImmutable $occurredAt, array $filters): bool
    {
        $from = $this->nullableString($filters['occurred_from'] ?? null);
        if ($from !== null && $occurredAt < new DateTimeImmutable($from)) {
            return false;
        }
        $to = $this->nullableString($filters['occurred_to'] ?? null);

        return $to === null || $occurredAt <= new DateTimeImmutable($to);
    }

    /**
     * @return array{endpoint:string|null,method:string|null}
     */
    private function endpointFilter(mixed $value): array
    {
        $value = $this->nullableString($value);
        if ($value !== null && preg_match('/^(GET|POST|PUT|PATCH|DELETE):(.+)$/', $value, $matches) === 1) {
            return ['method' => $matches[1], 'endpoint' => $matches[2]];
        }

        return ['method' => null, 'endpoint' => $value];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function limit(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(1, min(200, (int) $value)) : 50;
    }
}
