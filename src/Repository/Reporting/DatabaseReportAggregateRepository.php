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
                'cost_points',
                'occurred_at',
            )
            ->from('ad_serving_events')
            ->where('valid = :valid')
            ->andWhere('event_type IN (:impression, :click)')
            ->setParameter('valid', 1)
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
            $occurredAt = new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'));
            $organizationId = $filters['organization_id'] ?? $row['advertiser_organization_id'] ?? $row['publisher_organization_id'];
            $dateBucket = ($filters['granularity'] ?? 'day') === 'hour'
                ? $occurredAt->format('Y-m-d\TH:00:00P')
                : $occurredAt->format('Y-m-d');
            $key = implode('|', [
                $dateBucket,
                (string) ($organizationId ?? ''),
                (string) ($row['campaign_id'] ?? ''),
                (string) $row['site_id'],
                (string) $row['slot_id'],
            ]);

            $buckets[$key] ??= [
                'date' => $dateBucket,
                'organization_id' => $organizationId === null ? null : (int) $organizationId,
                'campaign_id' => $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
                'site_id' => (int) $row['site_id'],
                'slot_id' => (int) $row['slot_id'],
                'impressions' => 0,
                'clicks' => 0,
                'spend_points' => 0,
                'revenue_points' => 0,
            ];

            if ($row['event_type'] === 'impression') {
                ++$buckets[$key]['impressions'];
                $buckets[$key]['spend_points'] += max(0, (int) ($row['cost_points'] ?? 0));
            } elseif ($row['event_type'] === 'click') {
                ++$buckets[$key]['clicks'];
                $buckets[$key]['revenue_points'] += max(0, (int) ($row['cost_points'] ?? 0));
            }
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
        $this->connection->createQueryBuilder()
            ->delete('report_aggregates')
            ->where('granularity = :granularity')
            ->andWhere('bucket_start >= :from_time')
            ->andWhere('bucket_start < :to_time')
            ->setParameter('granularity', $granularity)
            ->setParameter('from_time', $this->formatDate($from))
            ->setParameter('to_time', $this->formatDate($to))
            ->executeStatement();

        $events = $this->connection->createQueryBuilder()
            ->select(
                'event_type',
                'site_id',
                'slot_id',
                'campaign_id',
                'advertiser_organization_id',
                'publisher_organization_id',
                'cost_points',
                'occurred_at',
            )
            ->from('ad_serving_events')
            ->where('valid = :valid')
            ->andWhere('event_type IN (:impression, :click)')
            ->andWhere('occurred_at >= :from_time')
            ->andWhere('occurred_at < :to_time')
            ->setParameter('valid', 1)
            ->setParameter('impression', 'impression')
            ->setParameter('click', 'click')
            ->setParameter('from_time', $this->formatDate($from))
            ->setParameter('to_time', $this->formatDate($to))
            ->fetchAllAssociative();

        $buckets = [];
        foreach ($events as $event) {
            foreach ($this->organizationIds($event) as $organizationId) {
                $occurredAt = new \DateTimeImmutable((string) $event['occurred_at'], new \DateTimeZone('UTC'));
                $bucketStart = $granularity === 'hour'
                    ? $occurredAt->format('Y-m-d H:00:00')
                    : $occurredAt->format('Y-m-d 00:00:00');
                $key = implode('|', [
                    $bucketStart,
                    (string) $organizationId,
                    (string) ($event['campaign_id'] ?? ''),
                    (string) $event['site_id'],
                    (string) $event['slot_id'],
                ]);

                $buckets[$key] ??= [
                    'bucket_start' => $bucketStart,
                    'organization_id' => $organizationId,
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
                    $buckets[$key]['spend_points'] += max(0, (int) ($event['cost_points'] ?? 0));
                } elseif ($event['event_type'] === 'click') {
                    ++$buckets[$key]['clicks'];
                    $buckets[$key]['revenue_points'] += max(0, (int) ($event['cost_points'] ?? 0));
                }
            }
        }

        ksort($buckets);
        $refreshedAt = $this->formatDate(new \DateTimeImmutable());
        foreach ($buckets as $bucket) {
            $this->connection->insert('report_aggregates', [
                'granularity' => $granularity,
                'bucket_start' => $bucket['bucket_start'],
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
     * @param array<string, mixed> $event
     * @return list<int>
     */
    private function organizationIds(array $event): array
    {
        $ids = [];
        foreach (['advertiser_organization_id', 'publisher_organization_id'] as $column) {
            if ($event[$column] !== null) {
                $ids[(int) $event[$column]] = (int) $event[$column];
            }
        }

        return array_values($ids);
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
}
