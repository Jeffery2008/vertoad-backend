<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use DateTimeImmutable;

final readonly class WebhookDelivery
{
    public function __construct(
        public string $delivery_id,
        public string $endpoint_url,
        public string $event_type,
        public string $payload_json,
        public string $status,
        public int $retry_count,
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
            'endpoint_url' => $this->endpoint_url,
            'event_type' => $this->event_type,
            'payload_json' => $this->payload_json,
            'status' => $this->status,
            'retry_count' => $this->retry_count,
            'last_error' => $this->last_error,
            'signature_header' => $this->signature_header,
            'created_at' => $this->created_at->format(DATE_ATOM),
            'delivered_at' => $this->delivered_at?->format(DATE_ATOM),
        ];
    }
}
