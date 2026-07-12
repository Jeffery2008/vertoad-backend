<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WebhookOutboxEvent
{
    public function __construct(
        public ?int $id,
        public WebhookEvent $event,
        public string $aggregateType,
        public string $aggregateId,
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $availableAt,
        public ?string $leaseToken,
        public ?DateTimeImmutable $leaseExpiresAt,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $dispatchedAt,
    ) {
        if ($this->id !== null && $this->id <= 0) {
            throw new InvalidArgumentException('Webhook outbox internal ID must be positive when provided.');
        }
        if (trim($this->aggregateType) === '' || strlen($this->aggregateType) > 80) {
            throw new InvalidArgumentException('Webhook outbox aggregate type must contain at most 80 characters.');
        }
        if (trim($this->aggregateId) === '' || strlen($this->aggregateId) > 160) {
            throw new InvalidArgumentException('Webhook outbox aggregate ID must contain at most 160 characters.');
        }
        if (!in_array($this->status, ['pending', 'processing', 'failed', 'dispatched'], true)) {
            throw new InvalidArgumentException('Webhook outbox status is not supported.');
        }
        if ($this->attemptCount < 0) {
            throw new InvalidArgumentException('Webhook outbox attempt count cannot be negative.');
        }
        if ($this->status === 'processing' && ($this->leaseToken === null || $this->leaseExpiresAt === null)) {
            throw new InvalidArgumentException('Processing webhook outbox events require an active lease.');
        }
    }
}
