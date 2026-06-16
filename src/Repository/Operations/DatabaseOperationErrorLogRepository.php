<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Operations\OperationErrorLog;

final readonly class DatabaseOperationErrorLogRepository implements OperationErrorLogRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(OperationErrorLog $entry): OperationErrorLog
    {
        $this->connection->insert('operation_error_logs', [
            'error_id' => $entry->error_id,
            'request_id' => $entry->request_id,
            'severity' => $entry->severity,
            'message' => $entry->message,
            'redacted_context_json' => json_encode($entry->redacted_context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'raw_context_json' => $entry->raw_context === null
                ? null
                : json_encode($entry->raw_context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'source' => $entry->source,
            'occurred_at' => $this->formatDate($entry->occurred_at),
        ]);

        return $entry;
    }

    public function find(string $errorId): ?OperationErrorLog
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_error_logs')
            ->where('error_id = :error_id')
            ->setParameter('error_id', trim($errorId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(?string $requestId = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_error_logs')
            ->orderBy('occurred_at', 'ASC')
            ->addOrderBy('error_id', 'ASC');

        if ($requestId !== null && trim($requestId) !== '') {
            $query->where('request_id = :request_id')
                ->setParameter('request_id', trim($requestId));
        }

        $rows = $query->fetchAllAssociative();

        return array_map(fn (array $row): OperationErrorLog => $this->hydrate($row), $rows);
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'error_id',
            'request_id',
            'severity',
            'message',
            'redacted_context_json',
            'raw_context_json',
            'source',
            'occurred_at',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OperationErrorLog
    {
        return new OperationErrorLog(
            error_id: (string) $row['error_id'],
            request_id: (string) $row['request_id'],
            severity: (string) $row['severity'],
            message: (string) $row['message'],
            redacted_context: $this->decodeContext((string) $row['redacted_context_json']),
            raw_context: $row['raw_context_json'] === null ? null : $this->decodeContext((string) $row['raw_context_json']),
            source: (string) $row['source'],
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

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
