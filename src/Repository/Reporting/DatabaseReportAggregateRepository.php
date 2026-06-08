<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Reporting\ReportAggregateRow;

final readonly class DatabaseReportAggregateRepository implements ReportAggregateRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function query(array $filters): array
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
}
