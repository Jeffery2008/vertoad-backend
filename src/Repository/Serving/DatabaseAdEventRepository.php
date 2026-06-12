<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Cron\ServingEventBufferInterface;
use VertoAD\Repository\Cron\ServingEventPersistenceInterface;

final readonly class DatabaseAdEventRepository implements AdEventRepositoryInterface, ServingEventBufferInterface, ServingEventPersistenceInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasEvent(string $eventType, string $eventId): bool
    {
        return $this->findEvent($eventType, $eventId) !== null;
    }

    public function findEvent(string $eventType, string $eventId): ?AdEvent
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('ad_serving_events')
            ->where('event_type = :event_type')
            ->andWhere('event_id = :event_id')
            ->setParameter('event_type', trim($eventType))
            ->setParameter('event_id', trim($eventId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function hasValidImpression(string $decisionId, string $viewerId): bool
    {
        return $this->exists([
            'event_type' => 'impression',
            'decision_id' => $decisionId,
            'viewer_id' => $viewerId,
            'valid' => 1,
        ]);
    }

    public function hasRecentValidClick(string $decisionId, string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): bool
    {
        $from = $occurredAt->modify('-' . $windowSeconds . ' seconds');
        $to = $occurredAt->modify('+' . $windowSeconds . ' seconds');
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('ad_serving_events')
            ->where('event_type = :event_type')
            ->andWhere('decision_id = :decision_id')
            ->andWhere('viewer_id = :viewer_id')
            ->andWhere('valid = :valid')
            ->andWhere('occurred_at > :from_time')
            ->andWhere('occurred_at < :to_time')
            ->setParameter('event_type', 'click')
            ->setParameter('decision_id', trim($decisionId))
            ->setParameter('viewer_id', trim($viewerId))
            ->setParameter('valid', 1)
            ->setParameter('from_time', $this->formatDate($from))
            ->setParameter('to_time', $this->formatDate($to))
            ->fetchOne();

        return (int) $count > 0;
    }

    public function recordImpression(AdDecision $decision, string $eventId, float $visibleRatio, int $visibleMs, DateTimeImmutable $occurredAt): void
    {
        $this->record('impression', $decision, $eventId, $occurredAt, true, null, $visibleRatio, $visibleMs);
    }

    public function recordClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt): void
    {
        $this->record('click', $decision, $eventId, $occurredAt, true, null);
    }

    public function recordInvalidClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, string $reason): void
    {
        $this->record('click', $decision, $eventId, $occurredAt, false, trim($reason));
    }

    public function persist(AdEvent $event): void
    {
        try {
            $this->connection->insert('ad_serving_events', [
                'event_type' => trim($event->eventType),
                'event_id' => trim($event->eventId),
                'decision_id' => $event->decisionId,
                'site_id' => $event->siteId,
                'slot_id' => $event->slotId,
                'viewer_id' => $event->viewerId,
                'ad_id' => $event->adId,
                'campaign_id' => $event->campaignId,
                'advertiser_organization_id' => $event->advertiserOrganizationId,
                'publisher_organization_id' => $event->publisherOrganizationId,
                'cost_points' => $event->costPoints,
                'occurred_at' => $this->formatDate($event->occurredAt),
                'valid' => $event->valid ? 1 : 0,
                'reason' => $event->reason,
                'visible_ratio' => $event->visibleRatio,
                'visible_ms' => $event->visibleMs,
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
        }

        $this->persistRawEvent($event);
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('ad_serving_events')
            ->where('processed_at IS NULL')
            ->orderBy('occurred_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->fetchAllAssociative();

        return array_map(fn (array $row): AdEvent => $this->hydrate($row), $rows);
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->connection->update(
            'ad_serving_events',
            ['processed_at' => $this->formatDate(new DateTimeImmutable())],
            ['event_type' => $event->eventType, 'event_id' => $event->eventId],
        );
    }

    public function fail(AdEvent $event, \Throwable $reason): void
    {
        $this->acknowledge($event);
    }

    /**
     * @param array<string, int|string> $criteria
     */
    private function exists(array $criteria): bool
    {
        $query = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('ad_serving_events');
        foreach ($criteria as $column => $value) {
            $query->andWhere($column . ' = :' . $column)->setParameter($column, $value);
        }

        return (int) $query->fetchOne() > 0;
    }

    private function record(
        string $eventType,
        AdDecision $decision,
        string $eventId,
        DateTimeImmutable $occurredAt,
        bool $valid,
        ?string $reason,
        ?float $visibleRatio = null,
        ?int $visibleMs = null,
    ): void {
        $this->persist(new AdEvent(
            eventType: $eventType,
            eventId: trim($eventId),
            decisionId: $decision->decisionId,
            siteId: $decision->siteId,
            slotId: $decision->slotId,
            viewerId: $decision->viewerId,
            adId: $decision->adId,
            campaignId: $decision->campaignId,
            advertiserOrganizationId: $decision->advertiserOrganizationId,
            publisherOrganizationId: $decision->publisherOrganizationId,
            costPoints: $eventType === 'impression' ? $decision->impressionCostPoints : $decision->clickCostPoints,
            occurredAt: $occurredAt,
            valid: $valid,
            reason: $reason,
            visibleRatio: $visibleRatio,
            visibleMs: $visibleMs,
        ));
    }

    private function persistRawEvent(AdEvent $event): void
    {
        try {
            $this->connection->insert('raw_events', [
                'event_uuid' => $this->rawEventUuid($event),
                'organization_id' => $event->advertiserOrganizationId ?? $event->publisherOrganizationId,
                'site_id' => $event->siteId,
                'ad_slot_id' => $event->slotId,
                'campaign_id' => $event->campaignId,
                'creative_id' => null,
                'event_type' => trim($event->eventType),
                'occurred_at' => $this->formatDate($event->occurredAt),
                'received_at' => $this->formatDate(new DateTimeImmutable()),
                'request_ip' => null,
                'user_agent' => null,
                'payload_json' => json_encode($this->rawPayload($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }

    private function rawEventUuid(AdEvent $event): string
    {
        return trim($event->eventType) . ':' . trim($event->eventId);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawPayload(AdEvent $event): array
    {
        return [
            'source' => 'ad_serving_events',
            'event_type' => trim($event->eventType),
            'event_id' => trim($event->eventId),
            'decision_id' => $event->decisionId,
            'site_id' => $event->siteId,
            'slot_id' => $event->slotId,
            'viewer_id' => $event->viewerId,
            'ad_id' => $event->adId,
            'campaign_id' => $event->campaignId,
            'advertiser_organization_id' => $event->advertiserOrganizationId,
            'publisher_organization_id' => $event->publisherOrganizationId,
            'cost_points' => $event->costPoints,
            'occurred_at' => $this->formatDate($event->occurredAt),
            'valid' => $event->valid,
            'reason' => $event->reason,
            'visible_ratio' => $event->visibleRatio,
            'visible_ms' => $event->visibleMs,
        ];
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'event_type',
            'event_id',
            'decision_id',
            'site_id',
            'slot_id',
            'viewer_id',
            'ad_id',
            'campaign_id',
            'advertiser_organization_id',
            'publisher_organization_id',
            'cost_points',
            'occurred_at',
            'valid',
            'reason',
            'visible_ratio',
            'visible_ms',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AdEvent
    {
        return new AdEvent(
            eventType: (string) $row['event_type'],
            eventId: (string) $row['event_id'],
            decisionId: (string) $row['decision_id'],
            siteId: (int) $row['site_id'],
            slotId: (int) $row['slot_id'],
            viewerId: (string) $row['viewer_id'],
            adId: $row['ad_id'] === null ? null : (string) $row['ad_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            advertiserOrganizationId: $row['advertiser_organization_id'] === null ? null : (int) $row['advertiser_organization_id'],
            publisherOrganizationId: $row['publisher_organization_id'] === null ? null : (int) $row['publisher_organization_id'],
            costPoints: $row['cost_points'] === null ? null : (int) $row['cost_points'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
            valid: (bool) $row['valid'],
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            visibleRatio: $row['visible_ratio'] === null ? null : (float) $row['visible_ratio'],
            visibleMs: $row['visible_ms'] === null ? null : (int) $row['visible_ms'],
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
