<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VertoAD\Domain\Billing\AdEventBillingResult;
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

    public function recordImpression(AdDecision $decision, string $eventId, float $visibleRatio, int $visibleMs, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->record('impression', $decision, $eventId, $occurredAt, true, null, $visibleRatio, $visibleMs, $requestId);
    }

    public function recordClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->record('click', $decision, $eventId, $occurredAt, true, null, null, null, $requestId);
    }

    public function recordInvalidClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, string $reason, ?string $requestId = null): void
    {
        $this->record('click', $decision, $eventId, $occurredAt, false, trim($reason), null, null, $requestId);
    }

    public function recordVideoEvent(AdDecision $decision, string $eventType, string $eventId, DateTimeImmutable $occurredAt, ?string $requestId = null): void
    {
        $this->record($eventType, $decision, $eventId, $occurredAt, true, null, null, null, $requestId);
    }

    public function searchEvents(array $filters): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('ad_serving_events')
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        if (isset($filters['request_id']) && trim((string) $filters['request_id']) !== '') {
            $query->andWhere('request_id = :request_id')
                ->setParameter('request_id', trim((string) $filters['request_id']));
        }
        if (isset($filters['ip_address']) && trim((string) $filters['ip_address']) !== '') {
            $query->andWhere('ip_address = :ip_address')
                ->setParameter('ip_address', trim((string) $filters['ip_address']));
        }
        if (isset($filters['event_type']) && trim((string) $filters['event_type']) !== '') {
            $query->andWhere('event_type = :event_type')
                ->setParameter('event_type', trim((string) $filters['event_type']));
        }
        if (isset($filters['occurred_from']) && trim((string) $filters['occurred_from']) !== '') {
            $query->andWhere('occurred_at >= :occurred_from')
                ->setParameter('occurred_from', $this->formatDate(new DateTimeImmutable((string) $filters['occurred_from'])));
        }
        if (isset($filters['occurred_to']) && trim((string) $filters['occurred_to']) !== '') {
            $query->andWhere('occurred_at <= :occurred_to')
                ->setParameter('occurred_to', $this->formatDate(new DateTimeImmutable((string) $filters['occurred_to'])));
        }
        if (isset($filters['limit']) && is_int($filters['limit']) && $filters['limit'] > 0) {
            $query->setMaxResults($filters['limit']);
        }

        return array_map(fn (array $row): AdEvent => $this->hydrate($row), $query->fetchAllAssociative());
    }

    public function persist(AdEvent $event): bool
    {
        try {
            return $this->connection->transactional(function () use ($event): bool {
                $this->claimEvent($event);

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
                    'request_id' => $event->requestId,
                    'ip_address' => $event->ipAddress,
                    'user_agent' => $event->userAgent,
                    'geo_code' => $event->geoCode,
                    'billing_status' => 'pending',
                    'billed_points' => 0,
                    'publisher_earning_points' => 0,
                    'billing_reason' => null,
                    'billing_processed_at' => null,
                    'processed_at' => null,
                ]);

                $this->persistRawEvent($event);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function findPendingDuplicate(AdEvent $event): ?AdEvent
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('ad_serving_events')
            ->where('event_type = :event_type')
            ->andWhere('event_id = :event_id')
            ->andWhere('processed_at IS NULL')
            ->setParameter('event_type', trim($event->eventType))
            ->setParameter('event_id', trim($event->eventId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function recordBillingResult(AdEvent $event, AdEventBillingResult $result, DateTimeImmutable $processedAt): void
    {
        $this->connection->update(
            'ad_serving_events',
            [
                'billing_status' => $result->billed ? 'billed' : 'skipped',
                'billed_points' => $result->grossPoints,
                'publisher_earning_points' => $result->publisherPoints,
                'billing_reason' => $result->reason,
                'billing_processed_at' => $this->formatDate($processedAt),
            ],
            $this->eventIdentity($event),
        );
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
            $this->eventIdentity($event),
        );
    }

    public function fail(AdEvent $event, \Throwable $reason): void
    {
        $this->recordFailure($event, $reason);
        $this->acknowledge($event);
    }

    public function recordFailure(AdEvent $event, \Throwable $reason): void
    {
        $this->connection->update(
            'ad_serving_events',
            [
                'billing_status' => 'failed',
                'billing_reason' => substr($reason->getMessage(), 0, 120),
                'billing_processed_at' => $this->formatDate(new DateTimeImmutable()),
            ],
            $this->eventIdentity($event),
        );
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
        ?string $requestId = null,
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
        ));
    }

    private function costPoints(string $eventType, AdDecision $decision): ?int
    {
        return match ($eventType) {
            'impression' => $decision->impressionCostPoints,
            'click' => $decision->clickCostPoints,
            default => 0,
        };
    }

    private function persistRawEvent(AdEvent $event): void
    {
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
            'request_id' => $event->requestId,
            'request_ip' => null,
            'user_agent' => $event->userAgent,
            'payload_json' => json_encode($this->rawPayload($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'processed_at' => null,
        ]);
    }

    private function claimEvent(AdEvent $event): void
    {
        $occurredAt = $this->formatDate($event->occurredAt);
        $createdAt = $this->formatDate(new DateTimeImmutable());

        $this->connection->insert('ad_serving_event_dedup', [
            'event_type' => trim($event->eventType),
            'event_id' => trim($event->eventId),
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
        ]);

        $this->connection->insert('raw_event_dedup', [
            'event_uuid' => $this->rawEventUuid($event),
            'occurred_at' => $occurredAt,
            'created_at' => $createdAt,
        ]);
    }

    private function rawEventUuid(AdEvent $event): string
    {
        return trim($event->eventType) . ':' . trim($event->eventId);
    }

    /**
     * @return array{event_type: string, event_id: string, occurred_at: string}
     */
    private function eventIdentity(AdEvent $event): array
    {
        return [
            'event_type' => trim($event->eventType),
            'event_id' => trim($event->eventId),
            'occurred_at' => $this->formatDate($event->occurredAt),
        ];
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
            'request_id' => $event->requestId,
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
            'geo_code' => $event->geoCode,
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
            'request_id',
            'ip_address',
            'user_agent',
            'geo_code',
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
            requestId: $row['request_id'] === null ? null : (string) $row['request_id'],
            ipAddress: $row['ip_address'] === null ? null : (string) $row['ip_address'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            geoCode: $row['geo_code'] === null ? null : (string) $row['geo_code'],
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
