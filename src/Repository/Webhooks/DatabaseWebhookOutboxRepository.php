<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Domain\Webhooks\WebhookOutboxEvent;

final readonly class DatabaseWebhookOutboxRepository implements WebhookOutboxRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function enqueue(WebhookEvent $event, string $aggregateType, string $aggregateId): WebhookOutboxEvent
    {
        $aggregateType = trim($aggregateType);
        $aggregateId = trim($aggregateId);
        if ($aggregateType === '' || strlen($aggregateType) > 80) {
            throw new InvalidArgumentException('Webhook outbox aggregate type must contain at most 80 characters.');
        }
        if ($aggregateId === '' || strlen($aggregateId) > 160) {
            throw new InvalidArgumentException('Webhook outbox aggregate ID must contain at most 160 characters.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $this->connection->insert('webhook_outbox_events', [
                'event_id' => $event->eventId,
                'organization_id' => $event->organizationId,
                'event_type' => $event->eventType,
                'api_version' => $event->apiVersion,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'data_json' => json_encode($event->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'request_id' => $event->requestId,
                'occurred_at' => $this->formatDate($event->occurredAt),
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => $this->formatDate($now),
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => null,
                'created_at' => $this->formatDate($now),
                'dispatched_at' => null,
            ], [
                'organization_id' => ParameterType::INTEGER,
                'attempt_count' => ParameterType::INTEGER,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findByEventId($event->eventId);
            if ($existing === null) {
                throw new RuntimeException('Webhook outbox idempotency lookup failed.');
            }
            $this->assertSameEvent($existing, $event, $aggregateType, $aggregateId);

            return $existing;
        }

        return $this->findByEventId($event->eventId)
            ?? throw new RuntimeException('Webhook outbox event could not be reloaded after insert.');
    }

    public function findByEventId(string $eventId): ?WebhookOutboxEvent
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return null;
        }

        $row = $this->baseQuery()
            ->where('event_id = :event_id')
            ->setParameter('event_id', $eventId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map(
            fn (array $row): WebhookOutboxEvent => $this->hydrate($row),
            $this->baseQuery()->orderBy('id', 'ASC')->fetchAllAssociative(),
        );
    }

    public function claimPending(int $limit, int $leaseSeconds, ?DateTimeImmutable $now = null): array
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Webhook outbox claim limit must be positive.');
        }
        if ($leaseSeconds <= 0) {
            throw new InvalidArgumentException('Webhook outbox lease duration must be positive.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');
        $leaseToken = 'whlease_' . bin2hex(random_bytes(20));

        return $this->connection->transactional(function () use (
            $limit,
            $now,
            $leaseExpiresAt,
            $leaseToken,
        ): array {
            $sql = <<<'SQL'
SELECT *
FROM webhook_outbox_events
WHERE (
    (status IN ('pending', 'failed') AND available_at <= ?)
    OR (status = 'processing' AND lease_expires_at <= ?)
)
ORDER BY id ASC
LIMIT %d
SQL;
            $sql = sprintf($sql, $limit);
            if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }

            $rows = $this->connection->fetchAllAssociative($sql, [
                $this->formatDate($now),
                $this->formatDate($now),
            ]);
            if ($rows === []) {
                return [];
            }

            $claimed = [];
            foreach ($rows as $row) {
                $attemptCount = (int) $row['attempt_count'] + 1;
                $this->connection->update('webhook_outbox_events', [
                    'status' => 'processing',
                    'attempt_count' => $attemptCount,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => $this->formatDate($leaseExpiresAt),
                ], ['id' => (int) $row['id']], [
                    'attempt_count' => ParameterType::INTEGER,
                    'id' => ParameterType::INTEGER,
                ]);

                $row['status'] = 'processing';
                $row['attempt_count'] = $attemptCount;
                $row['lease_token'] = $leaseToken;
                $row['lease_expires_at'] = $this->formatDate($leaseExpiresAt);
                $claimed[] = $this->hydrate($row);
            }

            return $claimed;
        });
    }

    public function markDispatched(string $eventId, string $leaseToken, DateTimeImmutable $dispatchedAt): bool
    {
        return $this->connection->update('webhook_outbox_events', [
            'status' => 'dispatched',
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error' => null,
            'dispatched_at' => $this->formatDate($dispatchedAt),
        ], [
            'event_id' => trim($eventId),
            'status' => 'processing',
            'lease_token' => trim($leaseToken),
        ]) === 1;
    }

    public function releaseAfterFailure(
        string $eventId,
        string $leaseToken,
        string $error,
        DateTimeImmutable $availableAt,
    ): bool {
        return $this->connection->update('webhook_outbox_events', [
            'status' => 'failed',
            'available_at' => $this->formatDate($availableAt),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error' => substr(trim($error), 0, 1000),
        ], [
            'event_id' => trim($eventId),
            'status' => 'processing',
            'lease_token' => trim($leaseToken),
        ]) === 1;
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'id',
                'event_id',
                'organization_id',
                'event_type',
                'api_version',
                'aggregate_type',
                'aggregate_id',
                'data_json',
                'request_id',
                'occurred_at',
                'status',
                'attempt_count',
                'available_at',
                'lease_token',
                'lease_expires_at',
                'last_error',
                'created_at',
                'dispatched_at',
            )
            ->from('webhook_outbox_events');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): WebhookOutboxEvent
    {
        $data = json_decode((string) $row['data_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Webhook outbox data must decode to an object.');
        }

        return new WebhookOutboxEvent(
            id: (int) $row['id'],
            event: new WebhookEvent(
                eventId: (string) $row['event_id'],
                eventType: (string) $row['event_type'],
                organizationId: (int) $row['organization_id'],
                data: $data,
                occurredAt: $this->parseDate((string) $row['occurred_at']),
                requestId: $row['request_id'] === null ? null : (string) $row['request_id'],
                apiVersion: (string) $row['api_version'],
            ),
            aggregateType: (string) $row['aggregate_type'],
            aggregateId: (string) $row['aggregate_id'],
            status: (string) $row['status'],
            attemptCount: (int) $row['attempt_count'],
            availableAt: $this->parseDate((string) $row['available_at']),
            leaseToken: $row['lease_token'] === null ? null : (string) $row['lease_token'],
            leaseExpiresAt: $row['lease_expires_at'] === null ? null : $this->parseDate((string) $row['lease_expires_at']),
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
            createdAt: $this->parseDate((string) $row['created_at']),
            dispatchedAt: $row['dispatched_at'] === null ? null : $this->parseDate((string) $row['dispatched_at']),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function assertSameEvent(
        WebhookOutboxEvent $existing,
        WebhookEvent $requested,
        string $aggregateType,
        string $aggregateId,
    ): void {
        if (
            $existing->event->eventType !== $requested->eventType
            || $existing->event->apiVersion !== $requested->apiVersion
            || $existing->event->organizationId !== $requested->organizationId
            || $existing->event->data !== $requested->data
            || $existing->event->requestId !== $requested->requestId
            || $this->formatDate($existing->event->occurredAt) !== $this->formatDate($requested->occurredAt)
            || $existing->aggregateType !== $aggregateType
            || $existing->aggregateId !== $aggregateId
        ) {
            throw new InvalidArgumentException('Webhook outbox event ID conflicts with an existing event.');
        }
    }
}
