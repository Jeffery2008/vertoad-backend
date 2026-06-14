<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use VertoAD\Domain\Reporting\ReportAggregateRow;

final readonly class DatabaseReportAggregateRepository implements ReportAggregateRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function query(array $filters): array
    {
        try {
            return $this->queryAggregates($filters);
        } catch (TableNotFoundException) {
            return $this->queryRawEvents($filters);
        }
    }

    public function refreshFromEvents(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('Report aggregate refresh end time must be after start time.');
        }

        $this->connection->beginTransaction();
        try {
            $metrics = [
                'day_rows' => $this->refreshGranularity('day', $from, $to),
                'hour_rows' => $this->refreshGranularity('hour', $from, $to),
            ];
            $this->connection->commit();

            return $metrics;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    private function queryAggregates(array $filters): array
    {
        $granularity = ($filters['granularity'] ?? 'day') === 'hour' ? 'hour' : 'day';
        $query = $this->connection->createQueryBuilder()
            ->select(
                'bucket_start',
                'organization_role',
                'organization_id',
                'campaign_id',
                'site_id',
                'slot_id',
                'geo',
                'device',
                'browser',
                'resolution',
                'risk_bucket',
                'impressions',
                'clicks',
                'spend_points',
                'revenue_points',
            )
            ->from('report_aggregates')
            ->where('granularity = :granularity')
            ->setParameter('granularity', $granularity);

        $role = $this->roleFilter($filters);
        if ($role !== null) {
            $query->andWhere('organization_role = :organization_role')
                ->setParameter('organization_role', $role);
        }

        foreach (['organization_id', 'campaign_id', 'site_id', 'slot_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->andWhere($filter . ' = :' . $filter)->setParameter($filter, (int) $filters[$filter]);
            }
        }

        if (isset($filters['from'])) {
            $query->andWhere('bucket_start >= :from_time')
                ->setParameter('from_time', $this->formatDate($filters['from']));
        }

        if (isset($filters['to'])) {
            $query->andWhere('bucket_start < :to_time')
                ->setParameter('to_time', $this->formatDate($filters['to']));
        }

        $query->orderBy('bucket_start', 'ASC')
            ->addOrderBy('organization_role', 'ASC')
            ->addOrderBy('organization_id', 'ASC')
            ->addOrderBy('campaign_id', 'ASC')
            ->addOrderBy('site_id', 'ASC')
            ->addOrderBy('slot_id', 'ASC');

        return array_map(
            fn (array $row): ReportAggregateRow => $this->aggregateRow($row, $granularity),
            $query->fetchAllAssociative(),
        );
    }

    private function queryRawEvents(array $filters): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(
                'event_type',
                'site_id',
                'slot_id',
                'campaign_id',
                'advertiser_organization_id',
                'publisher_organization_id',
                'billed_points',
                'publisher_earning_points',
                'occurred_at',
            )
            ->from('ad_serving_events')
            ->where('valid = :valid')
            ->andWhere('billing_status = :billing_status')
            ->andWhere('event_type IN (:impression, :click)')
            ->setParameter('valid', 1)
            ->setParameter('billing_status', 'billed')
            ->setParameter('impression', 'impression')
            ->setParameter('click', 'click');

        if (isset($filters['organization_id'])) {
            $query->andWhere('(advertiser_organization_id = :organization_id OR publisher_organization_id = :organization_id)')
                ->setParameter('organization_id', (int) $filters['organization_id']);
        }

        foreach (['campaign_id', 'site_id', 'slot_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->andWhere($filter . ' = :' . $filter)->setParameter($filter, (int) $filters[$filter]);
            }
        }

        if (isset($filters['from'])) {
            $query->andWhere('occurred_at >= :from_time')
                ->setParameter('from_time', $filters['from']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        if (isset($filters['to'])) {
            $query->andWhere('occurred_at < :to_time')
                ->setParameter('to_time', $filters['to']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        $buckets = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $this->addEventBuckets($buckets, $row, $filters);
        }

        ksort($buckets);

        return array_map(
            static fn (array $bucket): ReportAggregateRow => new ReportAggregateRow(
                date: $bucket['date'],
                organizationId: $bucket['organization_id'],
                campaignId: $bucket['campaign_id'],
                siteId: $bucket['site_id'],
                slotId: $bucket['slot_id'],
                geo: null,
                device: null,
                browser: null,
                resolution: null,
                riskBucket: null,
                impressions: $bucket['impressions'],
                clicks: $bucket['clicks'],
                spendPoints: $bucket['spend_points'],
                revenuePoints: $bucket['revenue_points'],
            ),
            array_values($buckets),
        );
    }

    private function refreshGranularity(string $granularity, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        [$bucketFrom, $bucketTo] = $this->refreshBucketWindow($granularity, $from, $to);

        $this->connection->createQueryBuilder()
            ->delete('report_aggregates')
            ->where('granularity = :granularity')
            ->andWhere('bucket_start >= :from_time')
            ->andWhere('bucket_start < :to_time')
            ->setParameter('granularity', $granularity)
            ->setParameter('from_time', $this->formatDate($bucketFrom))
            ->setParameter('to_time', $this->formatDate($bucketTo))
            ->executeStatement();

        $events = $this->connection->createQueryBuilder()
            ->select(
                'event_type',
                'site_id',
                'slot_id',
                'campaign_id',
                'advertiser_organization_id',
                'publisher_organization_id',
                'billed_points',
                'publisher_earning_points',
                'occurred_at',
            )
            ->from('ad_serving_events')
            ->where('valid = :valid')
            ->andWhere('billing_status = :billing_status')
            ->andWhere('event_type IN (:impression, :click)')
            ->andWhere('occurred_at >= :from_time')
            ->andWhere('occurred_at < :to_time')
            ->setParameter('valid', 1)
            ->setParameter('billing_status', 'billed')
            ->setParameter('impression', 'impression')
            ->setParameter('click', 'click')
            ->setParameter('from_time', $this->formatDate($bucketFrom))
            ->setParameter('to_time', $this->formatDate($bucketTo))
            ->fetchAllAssociative();

        $buckets = [];
        foreach ($events as $event) {
            $this->addEventBuckets($buckets, $event, ['granularity' => $granularity, 'include_all_roles' => true]);
        }

        ksort($buckets);
        $refreshedAt = $this->formatDate(new \DateTimeImmutable());
        foreach ($buckets as $bucket) {
            $this->connection->insert('report_aggregates', [
                'granularity' => $granularity,
                'bucket_start' => $bucket['bucket_start'],
                'dimension_key' => $bucket['dimension_key'],
                'organization_role' => $bucket['organization_role'],
                'organization_id' => $bucket['organization_id'],
                'campaign_id' => $bucket['campaign_id'],
                'site_id' => $bucket['site_id'],
                'slot_id' => $bucket['slot_id'],
                'geo' => null,
                'device' => null,
                'browser' => null,
                'resolution' => null,
                'risk_bucket' => null,
                'impressions' => $bucket['impressions'],
                'clicks' => $bucket['clicks'],
                'spend_points' => $bucket['spend_points'],
                'revenue_points' => $bucket['revenue_points'],
                'refreshed_at' => $refreshedAt,
            ]);
        }

        return count($buckets);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function aggregateRow(array $row, string $granularity): ReportAggregateRow
    {
        $bucketStart = new \DateTimeImmutable((string) $row['bucket_start'], new \DateTimeZone('UTC'));

        return new ReportAggregateRow(
            date: $granularity === 'hour' ? $bucketStart->format('Y-m-d\TH:00:00P') : $bucketStart->format('Y-m-d'),
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            siteId: $row['site_id'] === null ? null : (int) $row['site_id'],
            slotId: $row['slot_id'] === null ? null : (int) $row['slot_id'],
            geo: $row['geo'] === null ? null : (string) $row['geo'],
            device: $row['device'] === null ? null : (string) $row['device'],
            browser: $row['browser'] === null ? null : (string) $row['browser'],
            resolution: $row['resolution'] === null ? null : (string) $row['resolution'],
            riskBucket: $row['risk_bucket'] === null ? null : (string) $row['risk_bucket'],
            impressions: (int) $row['impressions'],
            clicks: (int) $row['clicks'],
            spendPoints: (int) $row['spend_points'],
            revenuePoints: (int) $row['revenue_points'],
        );
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function refreshBucketWindow(string $granularity, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromUtc = $from->setTimezone(new \DateTimeZone('UTC'));
        $toUtc = $to->setTimezone(new \DateTimeZone('UTC'));
        if ($granularity === 'hour') {
            $bucketFrom = $fromUtc->setTime((int) $fromUtc->format('H'), 0, 0);
            $bucketTo = $toUtc->setTime((int) $toUtc->format('H'), 0, 0);
            if ($bucketTo < $toUtc) {
                $bucketTo = $bucketTo->modify('+1 hour');
            }

            return [$bucketFrom, $bucketTo];
        }

        $bucketFrom = $fromUtc->setTime(0, 0, 0);
        $bucketTo = $toUtc->setTime(0, 0, 0);
        if ($bucketTo < $toUtc) {
            $bucketTo = $bucketTo->modify('+1 day');
        }

        return [$bucketFrom, $bucketTo];
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @param array<string, mixed> $event
     * @param array<string, mixed> $filters
     */
    private function addEventBuckets(array &$buckets, array $event, array $filters): void
    {
        $occurredAt = new \DateTimeImmutable((string) $event['occurred_at'], new \DateTimeZone('UTC'));
        $granularity = ($filters['granularity'] ?? 'day') === 'hour' ? 'hour' : 'day';
        $bucketStart = $granularity === 'hour'
            ? $occurredAt->format('Y-m-d H:00:00')
            : $occurredAt->format('Y-m-d 00:00:00');
        $date = $granularity === 'hour'
            ? $occurredAt->format('Y-m-d\TH:00:00P')
            : $occurredAt->format('Y-m-d');
        $roleFilter = $this->roleFilter($filters);
        $organizationFilter = isset($filters['organization_id']) ? (int) $filters['organization_id'] : null;

        foreach ($this->eventFacts($event) as $fact) {
            if ($roleFilter !== null && $fact['organization_role'] !== $roleFilter) {
                continue;
            }

            if ($organizationFilter !== null && $fact['organization_id'] !== $organizationFilter) {
                continue;
            }

            $key = implode('|', [
                $bucketStart,
                $fact['organization_role'],
                (string) ($fact['organization_id'] ?? ''),
                (string) ($event['campaign_id'] ?? ''),
                (string) $event['site_id'],
                (string) $event['slot_id'],
            ]);

            $buckets[$key] ??= [
                'date' => $date,
                'bucket_start' => $bucketStart,
                'dimension_key' => $this->dimensionKey($fact['organization_role'], $fact['organization_id'], $event),
                'organization_role' => $fact['organization_role'],
                'organization_id' => $fact['organization_id'],
                'campaign_id' => $event['campaign_id'] === null ? null : (int) $event['campaign_id'],
                'site_id' => (int) $event['site_id'],
                'slot_id' => (int) $event['slot_id'],
                'impressions' => 0,
                'clicks' => 0,
                'spend_points' => 0,
                'revenue_points' => 0,
            ];

            if ($event['event_type'] === 'impression') {
                ++$buckets[$key]['impressions'];
            } elseif ($event['event_type'] === 'click') {
                ++$buckets[$key]['clicks'];
            }
            $buckets[$key]['spend_points'] += $fact['spend_points'];
            $buckets[$key]['revenue_points'] += $fact['revenue_points'];
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array{organization_role:string, organization_id:?int, spend_points:int, revenue_points:int}>
     */
    private function eventFacts(array $event): array
    {
        $billedPoints = max(0, (int) ($event['billed_points'] ?? 0));
        $publisherPoints = max(0, (int) ($event['publisher_earning_points'] ?? 0));
        $facts = [[
            'organization_role' => 'platform',
            'organization_id' => null,
            'spend_points' => $billedPoints,
            'revenue_points' => $publisherPoints,
        ]];

        if ($event['advertiser_organization_id'] !== null) {
            $facts[] = [
                'organization_role' => 'advertiser',
                'organization_id' => (int) $event['advertiser_organization_id'],
                'spend_points' => $billedPoints,
                'revenue_points' => 0,
            ];
        }

        if ($event['publisher_organization_id'] !== null) {
            $facts[] = [
                'organization_role' => 'publisher',
                'organization_id' => (int) $event['publisher_organization_id'],
                'spend_points' => 0,
                'revenue_points' => $publisherPoints,
            ];
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function roleFilter(array $filters): ?string
    {
        if (($filters['include_all_roles'] ?? false) === true) {
            return null;
        }

        $portal = (string) ($filters['portal'] ?? 'admin');
        if (isset($filters['organization_id']) && $portal === 'admin') {
            return null;
        }

        return match ($portal) {
            'advertiser' => 'advertiser',
            'publisher' => 'publisher',
            default => 'platform',
        };
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dimensionKey(string $organizationRole, ?int $organizationId, array $event): string
    {
        return hash('sha256', json_encode([
            'organization_role' => $organizationRole,
            'organization_id' => $organizationId,
            'campaign_id' => $event['campaign_id'] === null ? null : (int) $event['campaign_id'],
            'site_id' => (int) $event['site_id'],
            'slot_id' => (int) $event['slot_id'],
            'geo' => null,
            'device' => null,
            'browser' => null,
            'resolution' => null,
            'risk_bucket' => null,
        ], JSON_THROW_ON_ERROR));
    }
}
