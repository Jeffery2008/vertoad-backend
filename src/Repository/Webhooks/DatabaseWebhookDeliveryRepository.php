<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Webhooks\WebhookDelivery;

final readonly class DatabaseWebhookDeliveryRepository implements WebhookDeliveryRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function queue(string $endpointUrl, string $eventType, array $payload): WebhookDelivery
    {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $delivery = new WebhookDelivery(
            delivery_id: 'whd_' . sha1(trim($endpointUrl) . '|' . trim($eventType) . '|' . $payloadJson . '|' . bin2hex(random_bytes(16))),
            endpoint_url: trim($endpointUrl),
            event_type: trim($eventType),
            payload_json: $payloadJson,
            status: 'queued',
            retry_count: 0,
            last_error: null,
            signature_header: null,
            created_at: new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('webhook_deliveries')
            ->where('delivery_id = :delivery_id')
            ->setParameter('delivery_id', trim($deliveryId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('webhook_deliveries')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('delivery_id', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookDelivery => $this->hydrate($row), $rows);
    }

    public function pendingRetry(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Webhook retry batch size must be positive.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('webhook_deliveries')
            ->where('status IN (:queued, :failed)')
            ->setParameter('queued', 'queued')
            ->setParameter('failed', 'failed')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('delivery_id', 'ASC')
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookDelivery => $this->hydrate($row), $rows);
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'delivery_id',
            'endpoint_url',
            'event_type',
            'payload_json',
            'status',
            'retry_count',
            'last_error',
            'signature_header',
            'created_at',
            'delivered_at',
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function rowFromDelivery(WebhookDelivery $delivery): array
    {
        return [
            'delivery_id' => $delivery->delivery_id,
            'endpoint_url' => $delivery->endpoint_url,
            'event_type' => $delivery->event_type,
            'payload_json' => $delivery->payload_json,
            'status' => $delivery->status,
            'retry_count' => $delivery->retry_count,
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
            endpoint_url: (string) $row['endpoint_url'],
            event_type: (string) $row['event_type'],
            payload_json: (string) $row['payload_json'],
            status: (string) $row['status'],
            retry_count: (int) $row['retry_count'],
            last_error: $row['last_error'] === null ? null : (string) $row['last_error'],
            signature_header: $row['signature_header'] === null ? null : (string) $row['signature_header'],
            created_at: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            delivered_at: $row['delivered_at'] === null ? null : new DateTimeImmutable((string) $row['delivered_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
