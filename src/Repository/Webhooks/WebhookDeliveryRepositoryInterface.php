<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;

interface WebhookDeliveryRepositoryInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function queueForEndpoint(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookDelivery;

    public function save(WebhookDelivery $delivery): WebhookDelivery;

    public function find(string $deliveryId): ?WebhookDelivery;

    /**
     * @return list<WebhookDelivery>
     */
    public function all(): array;

    /**
     * @return list<WebhookDelivery>
     */
    public function pendingRetry(int $limit, ?DateTimeImmutable $now = null): array;

    /**
     * @return list<WebhookDelivery>
     */
    public function listForOrganization(int $organizationId, ?string $endpointId = null, ?string $status = null, int $limit = 50): array;

    public function recordAttempt(
        string $deliveryId,
        int $attemptNumber,
        ?int $statusCode,
        ?string $error,
        ?string $signatureHeader,
        DateTimeImmutable $attemptedAt,
        int $durationMs,
    ): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function attemptsForDelivery(string $deliveryId): array;
}
