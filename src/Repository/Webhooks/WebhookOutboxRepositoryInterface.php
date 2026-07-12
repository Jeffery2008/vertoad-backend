<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Domain\Webhooks\WebhookOutboxEvent;

interface WebhookOutboxRepositoryInterface
{
    public function enqueue(WebhookEvent $event, string $aggregateType, string $aggregateId): WebhookOutboxEvent;

    public function findByEventId(string $eventId): ?WebhookOutboxEvent;

    /** @return list<WebhookOutboxEvent> */
    public function all(): array;

    /** @return list<WebhookOutboxEvent> */
    public function claimPending(
        int $limit,
        int $leaseSeconds,
        ?DateTimeImmutable $now = null,
    ): array;

    public function markDispatched(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $dispatchedAt,
    ): bool;

    public function releaseAfterFailure(
        string $eventId,
        string $leaseToken,
        string $error,
        DateTimeImmutable $availableAt,
    ): bool;
}
