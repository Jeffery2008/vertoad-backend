<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use DateTimeImmutable;

final readonly class WebhookDelivery
{
    public function __construct(
        public string $delivery_id,
        public int $organization_id,
        public int $webhook_endpoint_id,
        public string $endpoint_id,
        public string $endpoint_url,
        public string $event_type,
        public string $payload_json,
        public string $status,
        public int $retry_count,
        public DateTimeImmutable $next_attempt_at,
        public ?DateTimeImmutable $last_attempt_at,
        public ?int $last_status_code,
        public ?string $last_error,
        public ?string $signature_header,
        public DateTimeImmutable $created_at,
        public ?DateTimeImmutable $delivered_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delivery_id' => $this->delivery_id,
            'organization_id' => $this->organization_id,
            'endpoint_id' => $this->endpoint_id,
            'endpoint_url' => $this->endpoint_url,
            'event_type' => $this->event_type,
            'payload_json' => $this->payload_json,
            'status' => $this->status,
            'retry_count' => $this->retry_count,
            'next_attempt_at' => $this->next_attempt_at->format(DATE_ATOM),
            'last_attempt_at' => $this->last_attempt_at?->format(DATE_ATOM),
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'signature_header' => $this->signature_header,
            'created_at' => $this->created_at->format(DATE_ATOM),
            'delivered_at' => $this->delivered_at?->format(DATE_ATOM),
        ];
    }
}
