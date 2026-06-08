<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use VertoAD\Domain\Webhooks\WebhookDelivery;

final class InMemoryWebhookDeliveryRepository implements WebhookDeliveryRepositoryInterface
{
    /** @var array<string, WebhookDelivery> */
    private array $deliveries = [];

    public function queue(string $endpointUrl, string $eventType, array $payload): WebhookDelivery
    {
        $delivery = new WebhookDelivery(
            delivery_id: 'whd_' . sha1($endpointUrl . '|' . $eventType . '|' . json_encode($payload, JSON_THROW_ON_ERROR) . '|' . microtime(true)),
            endpoint_url: $endpointUrl,
            event_type: $eventType,
            payload_json: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            status: 'queued',
            retry_count: 0,
            last_error: null,
            signature_header: null,
            created_at: new DateTimeImmutable(),
            delivered_at: null,
        );

        return $this->save($delivery);
    }

    public function save(WebhookDelivery $delivery): WebhookDelivery
    {
        $this->deliveries[$delivery->delivery_id] = $delivery;

        return $delivery;
    }

    public function find(string $deliveryId): ?WebhookDelivery
    {
        return $this->deliveries[$deliveryId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->deliveries);
    }
}
