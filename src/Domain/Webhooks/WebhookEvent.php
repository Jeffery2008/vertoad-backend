<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class WebhookEvent
{
    public const string API_VERSION = '2026-07-12';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $organizationId,
        public array $data,
        public DateTimeImmutable $occurredAt,
        public ?string $requestId,
        public string $apiVersion = self::API_VERSION,
    ) {
        if (trim($this->eventId) === '' || strlen($this->eventId) > 160) {
            throw new InvalidArgumentException('Webhook event ID must contain at most 160 characters.');
        }
        if (!WebhookEventType::isSubscribable($this->eventType)) {
            throw new InvalidArgumentException('Webhook event type is not supported.');
        }
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('Webhook event organization ID must be positive.');
        }
        if ($this->requestId !== null && (trim($this->requestId) === '' || strlen($this->requestId) > 160)) {
            throw new InvalidArgumentException('Webhook event request ID must contain at most 160 characters when provided.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->apiVersion) !== 1) {
            throw new InvalidArgumentException('Webhook event API version must use YYYY-MM-DD format.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->eventType,
            'api_version' => $this->apiVersion,
            'created_at' => $this->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            'organization_id' => $this->organizationId,
            'request_id' => $this->requestId,
            'data' => $this->data,
        ];
    }

    public function payloadJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
