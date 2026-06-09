<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;

final readonly class DatabaseWebhookDeliveryRepository implements WebhookDeliveryRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function queueForEndpoint(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookDelivery
    {
        if ($endpoint->id === null) {
            throw new \InvalidArgumentException('Webhook endpoint internal ID is required to queue a delivery.');
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $delivery = new WebhookDelivery(
            delivery_id: 'whd_' . sha1($endpoint->endpointId . '|' . trim($eventType) . '|' . $payloadJson . '|' . bin2hex(random_bytes(16))),
            organization_id: $endpoint->organizationId,
            webhook_endpoint_id: $endpoint->id,
            endpoint_id: $endpoint->endpointId,
            endpoint_url: $endpoint->endpointUrl,
            event_type: trim($eventType),
            payload_json: $payloadJson,
            status: 'queued',
            retry_count: 0,
            next_attempt_at: $now,
            last_attempt_at: null,
            last_status_code: null,
            last_error: null,
            signature_header: null,
            created_at: $now,
            delivered_at: null,
        );

        return $this->save($delivery);
    }

    public function save(WebhookDelivery $delivery): WebhookDelivery
    {
        $row = $this->rowFromDelivery($delivery);
        if ($this->find($delivery->delivery_id) === null) {
            $this->connection->insert('webhook_deliveries', $row);

            return $delivery;
        }

        $this->connection->update('webhook_deliveries', $row, ['delivery_id' => $delivery->delivery_id]);

        return $delivery;
    }

    public function find(string $deliveryId): ?WebhookDelivery
    {
        $row = $this->baseQuery()
            ->where('wd.delivery_id = :delivery_id')
            ->setParameter('delivery_id', trim($deliveryId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->baseQuery()
            ->orderBy('wd.created_at', 'ASC')
            ->addOrderBy('wd.delivery_id', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookDelivery => $this->hydrate($row), $rows);
    }

    public function pendingRetry(int $limit, ?DateTimeImmutable $now = null): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Webhook retry batch size must be positive.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = $this->baseQuery()
            ->where('wd.status IN (:queued, :failed)')
            ->andWhere('wd.next_attempt_at <= :now')
            ->setParameter('queued', 'queued')
            ->setParameter('failed', 'failed')
            ->setParameter('now', $this->formatDate($now))
            ->orderBy('wd.next_attempt_at', 'ASC')
            ->addOrderBy('wd.created_at', 'ASC')
            ->addOrderBy('wd.delivery_id', 'ASC')
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookDelivery => $this->hydrate($row), $rows);
    }

    public function listForOrganization(int $organizationId, ?string $endpointId = null, ?string $status = null, int $limit = 50): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        if ($limit <= 0) {
            throw new \InvalidArgumentException('Webhook delivery list limit must be positive.');
        }

        $query = $this->baseQuery()
            ->where('wd.organization_id = :organization_id')
            ->setParameter('organization_id', $organizationId);
        if (is_string($endpointId) && trim($endpointId) !== '') {
            $query->andWhere('we.endpoint_id = :endpoint_id')
                ->setParameter('endpoint_id', trim($endpointId));
        }

        if (is_string($status) && trim($status) !== '') {
            $query->andWhere('wd.status = :status')
                ->setParameter('status', trim($status));
        }

        $rows = $query
            ->orderBy('wd.created_at', 'DESC')
            ->addOrderBy('wd.delivery_id', 'ASC')
            ->setMaxResults(min($limit, 100))
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookDelivery => $this->hydrate($row), $rows);
    }

    public function recordAttempt(
        string $deliveryId,
        int $attemptNumber,
        ?int $statusCode,
        ?string $error,
        ?string $signatureHeader,
        DateTimeImmutable $attemptedAt,
        int $durationMs,
    ): void {
        $this->connection->insert('webhook_delivery_attempts', [
            'delivery_id' => trim($deliveryId),
            'attempt_number' => $attemptNumber,
            'status_code' => $statusCode,
            'error' => $error,
            'signature_header' => $signatureHeader,
            'attempted_at' => $this->formatDate($attemptedAt),
            'duration_ms' => $durationMs,
        ], [
            'attempt_number' => ParameterType::INTEGER,
            'status_code' => $statusCode === null ? ParameterType::NULL : ParameterType::INTEGER,
            'duration_ms' => ParameterType::INTEGER,
        ]);
    }

    public function attemptsForDelivery(string $deliveryId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'delivery_id', 'attempt_number', 'status_code', 'error', 'signature_header', 'attempted_at', 'duration_ms')
            ->from('webhook_delivery_attempts')
            ->where('delivery_id = :delivery_id')
            ->orderBy('attempt_number', 'ASC')
            ->setParameter('delivery_id', trim($deliveryId))
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'delivery_id' => (string) $row['delivery_id'],
            'attempt_number' => (int) $row['attempt_number'],
            'status_code' => $row['status_code'] === null ? null : (int) $row['status_code'],
            'error' => $row['error'] === null ? null : (string) $row['error'],
            'signature_header' => $row['signature_header'] === null ? null : (string) $row['signature_header'],
            'attempted_at' => (string) $row['attempted_at'],
            'duration_ms' => (int) $row['duration_ms'],
        ], $rows);
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'wd.delivery_id',
                'wd.organization_id',
                'wd.webhook_endpoint_id',
                'we.endpoint_id',
                'wd.endpoint_url',
                'wd.event_type',
                'wd.payload_json',
                'wd.status',
                'wd.retry_count',
                'wd.next_attempt_at',
                'wd.last_attempt_at',
                'wd.last_status_code',
                'wd.last_error',
                'wd.signature_header',
                'wd.created_at',
                'wd.delivered_at',
            )
            ->from('webhook_deliveries', 'wd')
            ->innerJoin('wd', 'webhook_endpoints', 'we', 'we.id = wd.webhook_endpoint_id');
    }

    /**
     * @return array<string, int|string|null>
     */
    private function rowFromDelivery(WebhookDelivery $delivery): array
    {
        return [
            'delivery_id' => $delivery->delivery_id,
            'organization_id' => $delivery->organization_id,
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'endpoint_url' => $delivery->endpoint_url,
            'event_type' => $delivery->event_type,
            'payload_json' => $delivery->payload_json,
            'status' => $delivery->status,
            'retry_count' => $delivery->retry_count,
            'next_attempt_at' => $this->formatDate($delivery->next_attempt_at),
            'last_attempt_at' => $delivery->last_attempt_at === null ? null : $this->formatDate($delivery->last_attempt_at),
            'last_status_code' => $delivery->last_status_code,
            'last_error' => $delivery->last_error,
            'signature_header' => $delivery->signature_header,
            'created_at' => $this->formatDate($delivery->created_at),
            'delivered_at' => $delivery->delivered_at === null ? null : $this->formatDate($delivery->delivered_at),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WebhookDelivery
    {
        return new WebhookDelivery(
            delivery_id: (string) $row['delivery_id'],
            organization_id: (int) $row['organization_id'],
            webhook_endpoint_id: (int) $row['webhook_endpoint_id'],
            endpoint_id: (string) $row['endpoint_id'],
            endpoint_url: (string) $row['endpoint_url'],
            event_type: (string) $row['event_type'],
            payload_json: (string) $row['payload_json'],
            status: (string) $row['status'],
            retry_count: (int) $row['retry_count'],
            next_attempt_at: $this->parseDate((string) $row['next_attempt_at']),
            last_attempt_at: $row['last_attempt_at'] === null ? null : $this->parseDate((string) $row['last_attempt_at']),
            last_status_code: $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
            last_error: $row['last_error'] === null ? null : (string) $row['last_error'],
            signature_header: $row['signature_header'] === null ? null : (string) $row['signature_header'],
            created_at: $this->parseDate((string) $row['created_at']),
            delivered_at: $row['delivered_at'] === null ? null : $this->parseDate((string) $row['delivered_at']),
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
}
