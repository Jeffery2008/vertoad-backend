<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Operations\OperationSystemLog;

final readonly class DatabaseOperationSystemLogRepository implements OperationSystemLogRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(OperationSystemLog $entry): OperationSystemLog
    {
        $this->connection->insert('operation_system_logs', [
            'log_id' => $entry->log_id,
            'request_id' => $entry->request_id,
            'level' => $entry->level,
            'message' => $entry->message,
            'endpoint' => $entry->endpoint,
            'http_method' => $entry->http_method,
            'ip_address' => $entry->ip_address,
            'source' => $entry->source,
            'redacted_context_json' => json_encode($entry->redacted_context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'raw_context_json' => $entry->raw_context === null ? null : json_encode($entry->raw_context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $this->formatDate($entry->occurred_at),
        ]);

        return $entry;
    }

    public function find(string $logId): ?OperationSystemLog
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_system_logs')
            ->where('log_id = :log_id')
            ->setParameter('log_id', trim($logId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function search(array $filters = []): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_system_logs')
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('log_id', 'DESC')
            ->setMaxResults($this->limit($filters['limit'] ?? null));
        foreach (['request_id', 'ip_address'] as $field) {
            $value = $this->nullableString($filters[$field] ?? null);
            if ($value !== null) {
                $query->andWhere($field . ' = :' . $field)->setParameter($field, $value);
            }
        }
        foreach (['occurred_from' => '>=', 'occurred_to' => '<='] as $field => $operator) {
            $value = $this->nullableString($filters[$field] ?? null);
            if ($value !== null) {
                $query->andWhere('occurred_at ' . $operator . ' :' . $field)->setParameter($field, $this->formatDate(new DateTimeImmutable($value)));
            }
        }
        $endpoint = $this->endpointFilter($filters['endpoint'] ?? null);
        if ($endpoint['endpoint'] !== null) {
            $query->andWhere('endpoint = :endpoint')->setParameter('endpoint', $endpoint['endpoint']);
        }
        if ($endpoint['method'] !== null) {
            $query->andWhere('http_method = :http_method')->setParameter('http_method', $endpoint['method']);
        }

        return array_map(fn (array $row): OperationSystemLog => $this->hydrate($row), $query->fetchAllAssociative());
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'log_id',
            'request_id',
            'level',
            'message',
            'endpoint',
            'http_method',
            'ip_address',
            'source',
            'redacted_context_json',
            'raw_context_json',
            'occurred_at',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OperationSystemLog
    {
        return new OperationSystemLog(
            log_id: (string) $row['log_id'],
            request_id: (string) $row['request_id'],
            level: (string) $row['level'],
            message: (string) $row['message'],
            endpoint: $row['endpoint'] === null ? null : (string) $row['endpoint'],
            http_method: $row['http_method'] === null ? null : (string) $row['http_method'],
            ip_address: $row['ip_address'] === null ? null : (string) $row['ip_address'],
            source: (string) $row['source'],
            redacted_context: $this->decodeContext((string) $row['redacted_context_json']),
            raw_context: $row['raw_context_json'] === null ? null : $this->decodeContext((string) $row['raw_context_json']),
            occurred_at: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContext(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
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

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
