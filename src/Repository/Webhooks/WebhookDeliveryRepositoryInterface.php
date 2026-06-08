<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use VertoAD\Domain\Webhooks\WebhookDelivery;

interface WebhookDeliveryRepositoryInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function queue(string $endpointUrl, string $eventType, array $payload): WebhookDelivery;

    public function save(WebhookDelivery $delivery): WebhookDelivery;

    public function find(string $deliveryId): ?WebhookDelivery;

    /**
     * @return list<WebhookDelivery>
     */
    public function all(): array;
}
