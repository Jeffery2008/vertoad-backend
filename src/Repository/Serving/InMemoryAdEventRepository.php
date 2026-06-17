<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Cron\ServingEventBufferInterface;

final class InMemoryAdEventRepository implements AdEventRepositoryInterface, ServingEventBufferInterface
{
    /** @var array<string, true> */
    private array $eventIds = [];

    /** @var list<AdEvent> */
    private array $events = [];

    public function hasEvent(string $eventType, string $eventId): bool
    {
        return isset($this->eventIds[$this->key($eventType, $eventId)]);
    }

    public function findEvent(string $eventType, string $eventId): ?AdEvent
    {
        foreach ($this->events as $event) {
            if ($event->eventType === $eventType && $event->eventId === $eventId) {
                return $event;
            }
        }

        return null;
    }

    public function hasValidImpression(string $decisionId, string $viewerId): bool
    {
        foreach ($this->events as $event) {
            if (
                $event->eventType === 'impression'
                && $event->decisionId === $decisionId
                && $event->viewerId === $viewerId
                && $event->valid
            ) {
                return true;
            }
        }

        return false;
    }

    public function hasRecentValidClick(string $decisionId, string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): bool
    {
        foreach ($this->events as $event) {
            if (
                $event->eventType === 'click'
                && $event->decisionId === $decisionId
                && $event->viewerId === $viewerId
                && $event->valid
                && abs($occurredAt->getTimestamp() - $event->occurredAt->getTimestamp()) < $windowSeconds
            ) {
                return true;
            }
        }

        return false;
    }

    public function recordImpression(AdDecision $decision, string $eventId, float $visibleRatio, int $visibleMs, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->eventIds[$this->key('impression', $eventId)] = true;
        $this->events[] = $this->event('impression', $decision, $eventId, $occurredAt, true, null, $visibleRatio, $visibleMs, $requestId);
    }

    public function recordClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->eventIds[$this->key('click', $eventId)] = true;
        $this->events[] = $this->event('click', $decision, $eventId, $occurredAt, true, null, null, null, $requestId);
    }

    public function recordInvalidClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, string $reason, ?string $requestId = null): void
    {
        $this->eventIds[$this->key('click', $eventId)] = true;
        $this->events[] = $this->event('click', $decision, $eventId, $occurredAt, false, $reason, null, null, $requestId);
    }

    public function recordVideoEvent(AdDecision $decision, string $eventType, string $eventId, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->eventIds[$this->key($eventType, $eventId)] = true;
        $this->events[] = $this->event($eventType, $decision, $eventId, $occurredAt, true, null, null, null, $requestId);
    }

    public function searchEvents(array $filters): array
    {
        $items = array_values(array_filter(
            $this->events,
            function (AdEvent $event) use ($filters): bool {
                if (isset($filters['request_id']) && (string) $filters['request_id'] !== (string) ($event->requestId ?? '')) {
                    return false;
                }
                if (isset($filters['ip_address']) && (string) $filters['ip_address'] !== (string) ($event->ipAddress ?? '')) {
                    return false;
                }
                if (isset($filters['event_type']) && (string) $filters['event_type'] !== $event->eventType) {
                    return false;
                }
                if (isset($filters['occurred_from']) && $event->occurredAt < new DateTimeImmutable((string) $filters['occurred_from'])) {
                    return false;
                }
                if (isset($filters['occurred_to']) && $event->occurredAt > new DateTimeImmutable((string) $filters['occurred_to'])) {
                    return false;
                }

                return true;
            },
        ));

        if (isset($filters['limit']) && is_int($filters['limit']) && $filters['limit'] > 0) {
            return array_slice($items, 0, $filters['limit']);
        }

        return $items;
    }

    public function impressionCount(): int
    {
        return $this->validCount('impression');
    }

    public function clickCount(): int
    {
        return $this->validCount('click');
    }

    /**
     * @return list<AdEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        return array_slice($this->events, 0, $limit);
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->remove($event);
    }

    public function fail(AdEvent $event, \Throwable $reason): void
    {
        $this->remove($event);
    }

    private function remove(AdEvent $event): void
    {
        $key = $this->key($event->eventType, $event->eventId);
        unset($this->eventIds[$key]);
        $this->events = array_values(array_filter(
            $this->events,
            fn (AdEvent $pending): bool => $this->key($pending->eventType, $pending->eventId) !== $key,
        ));
    }

    private function key(string $eventType, string $eventId): string
    {
        return $eventType . ':' . $eventId;
    }

    private function event(
        string $eventType,
        AdDecision $decision,
        string $eventId,
        DateTimeImmutable $occurredAt,
        bool $valid,
        ?string $reason,
        ?float $visibleRatio = null,
        ?int $visibleMs = null,
        ?string $requestId = null,
    ): AdEvent {
        return new AdEvent(
            eventType: $eventType,
            eventId: $eventId,
            decisionId: $decision->decisionId,
            siteId: $decision->siteId,
            slotId: $decision->slotId,
            viewerId: $decision->viewerId,
            adId: $decision->adId,
            campaignId: $decision->campaignId,
            advertiserOrganizationId: $decision->advertiserOrganizationId,
            publisherOrganizationId: $decision->publisherOrganizationId,
            costPoints: $this->costPoints($eventType, $decision),
            occurredAt: $occurredAt,
            valid: $valid,
            reason: $reason,
            visibleRatio: $visibleRatio,
            visibleMs: $visibleMs,
            requestId: $requestId,
            ipAddress: $decision->ipAddress,
            userAgent: $decision->userAgent,
            geoCode: $decision->geoCode,
        );
    }

    private function costPoints(string $eventType, AdDecision $decision): ?int
    {
        return match ($eventType) {
            'impression' => $decision->impressionCostPoints,
            'click' => $decision->clickCostPoints,
            default => 0,
        };
    }

    private function validCount(string $eventType): int
    {
        $count = 0;
        foreach ($this->events as $event) {
            if ($event->eventType === $eventType && $event->valid) {
                $count++;
            }
        }

        return $count;
    }
}
