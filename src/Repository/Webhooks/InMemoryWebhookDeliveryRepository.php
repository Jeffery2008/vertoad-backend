<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Http\RequestIdContext;

final class InMemoryWebhookDeliveryRepository implements WebhookDeliveryRepositoryInterface
{
    /** @var array<string, WebhookDelivery> */
    private array $deliveries = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $attempts = [];

    public function queueForEndpoint(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookDelivery
    {
        if ($endpoint->id === null) {
            throw new \InvalidArgumentException('Webhook endpoint internal ID is required to queue a delivery.');
        }

        $requestId = $this->requestIdFromPayload($payload) ?? RequestIdContext::current();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $delivery = new WebhookDelivery(
            delivery_id: 'whd_' . sha1($endpoint->endpointId . '|' . $eventType . '|' . $payloadJson . '|' . microtime(true)),
            organization_id: $endpoint->organizationId,
            webhook_endpoint_id: $endpoint->id,
            endpoint_id: $endpoint->endpointId,
            endpoint_url: $endpoint->endpointUrl,
            event_type: trim($eventType),
            payload_json: $payloadJson,
            request_id: $requestId,
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

    public function pendingRetry(int $limit, ?DateTimeImmutable $now = null, int $maxRetryCount = 3): array
    {
        if ($limit <= 0) {
            return [];
        }
        if ($maxRetryCount <= 0) {
            throw new \InvalidArgumentException('Webhook retry cap must be positive.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return array_slice(array_values(array_filter(
            $this->deliveries,
            static fn (WebhookDelivery $delivery): bool =>
                in_array($delivery->status, ['queued', 'failed'], true)
                && $delivery->next_attempt_at <= $now
            && $delivery->retry_count < $maxRetryCount,
        )), 0, $limit);
    }

    public function markDueRetriesExhausted(int $limit, ?DateTimeImmutable $now = null, int $maxRetryCount = 3): array
    {
        if ($limit <= 0) {
            return [];
        }
        if ($maxRetryCount <= 0) {
            throw new \InvalidArgumentException('Webhook retry cap must be positive.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $candidates = array_slice(array_values(array_filter(
            $this->deliveries,
            static fn (WebhookDelivery $delivery): bool =>
                $delivery->status === 'failed'
                && $delivery->next_attempt_at <= $now
                && $delivery->retry_count >= $maxRetryCount,
        )), 0, $limit);

        $exhausted = [];
        foreach ($candidates as $delivery) {
            $terminalAt = $delivery->last_attempt_at ?? $now;
            $exhausted[] = $this->save(new WebhookDelivery(
                delivery_id: $delivery->delivery_id,
                organization_id: $delivery->organization_id,
                webhook_endpoint_id: $delivery->webhook_endpoint_id,
                endpoint_id: $delivery->endpoint_id,
                endpoint_url: $delivery->endpoint_url,
                event_type: $delivery->event_type,
                payload_json: $delivery->payload_json,
                request_id: $delivery->request_id,
                status: 'exhausted',
                retry_count: $delivery->retry_count,
                next_attempt_at: $terminalAt,
                last_attempt_at: $delivery->last_attempt_at,
                last_status_code: $delivery->last_status_code,
                last_error: $delivery->last_error,
                signature_header: $delivery->signature_header,
                created_at: $delivery->created_at,
                delivered_at: null,
            ));
        }

        return $exhausted;
    }

    public function listForOrganization(int $organizationId, ?string $endpointId = null, ?string $status = null, int $limit = 50): array
    {
        if ($organizationId <= 0 || $limit <= 0) {
            return [];
        }

        return array_slice(array_values(array_filter(
            $this->deliveries,
            static fn (WebhookDelivery $delivery): bool =>
                $delivery->organization_id === $organizationId
                && ($endpointId === null || trim($endpointId) === '' || $delivery->endpoint_id === trim($endpointId))
                && ($status === null || trim($status) === '' || $delivery->status === trim($status)),
        )), 0, min($limit, 100));
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
        $this->attempts[$deliveryId] ??= [];
        $this->attempts[$deliveryId][] = [
            'id' => count($this->attempts[$deliveryId]) + 1,
            'delivery_id' => $deliveryId,
            'attempt_number' => $attemptNumber,
            'status_code' => $statusCode,
            'error' => $error,
            'signature_header' => $signatureHeader,
            'attempted_at' => $attemptedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $durationMs,
        ];
    }

    public function attemptsForDelivery(string $deliveryId): array
    {
        return $this->attempts[$deliveryId] ?? [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function requestIdFromPayload(array $payload): ?string
    {
        $requestId = $payload['request_id'] ?? null;
        if (!is_scalar($requestId)) {
            return null;
        }

        $requestId = trim((string) $requestId);

        return $requestId === '' ? null : $requestId;
    }
}
