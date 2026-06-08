<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use VertoAD\Domain\Reporting\ReportAggregateRow;
use VertoAD\Domain\Serving\AdEvent;

final readonly class InMemoryReportAggregateRepository implements ReportAggregateRepositoryInterface
{
    /**
     * @param list<AdEvent>|callable():list<AdEvent> $events
     */
    public function __construct(private mixed $events = [])
    {
        if (!is_array($events) && !is_callable($events)) {
            throw new \InvalidArgumentException('Report event source must be an array or callable.');
        }
    }

    public function query(array $filters): array
    {
        $buckets = [];
        foreach ($this->events() as $event) {
            if (!$event->valid || !in_array($event->eventType, ['impression', 'click'], true)) {
                continue;
            }

            if (!$this->matches($event, $filters)) {
                continue;
            }

            $organizationId = $filters['organization_id'] ?? $event->advertiserOrganizationId ?? $event->publisherOrganizationId;
            $dateBucket = $this->dateBucket($event, $filters);
            $key = implode('|', [
                $dateBucket,
                (string) ($organizationId ?? ''),
                (string) ($event->campaignId ?? ''),
                (string) $event->siteId,
                (string) $event->slotId,
            ]);

            $buckets[$key] ??= [
                'date' => $dateBucket,
                'organization_id' => $organizationId,
                'campaign_id' => $event->campaignId,
                'site_id' => $event->siteId,
                'slot_id' => $event->slotId,
                'impressions' => 0,
                'clicks' => 0,
                'spend_points' => 0,
                'revenue_points' => 0,
            ];

            if ($event->eventType === 'impression') {
                ++$buckets[$key]['impressions'];
                $buckets[$key]['spend_points'] += max(0, (int) ($event->costPoints ?? 0));
            } elseif ($event->eventType === 'click') {
                ++$buckets[$key]['clicks'];
                $buckets[$key]['revenue_points'] += max(0, (int) ($event->costPoints ?? 0));
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

    /**
     * @return list<AdEvent>
     */
    private function events(): array
    {
        if (is_callable($this->events)) {
            return ($this->events)();
        }

        return $this->events;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function matches(AdEvent $event, array $filters): bool
    {
        if (isset($filters['organization_id'])) {
            $organizationId = (int) $filters['organization_id'];
            if ($event->advertiserOrganizationId !== $organizationId && $event->publisherOrganizationId !== $organizationId) {
                return false;
            }
        }

        foreach ([
            'campaign_id' => $event->campaignId,
            'site_id' => $event->siteId,
            'slot_id' => $event->slotId,
        ] as $filter => $value) {
            if (isset($filters[$filter]) && $value !== (int) $filters[$filter]) {
                return false;
            }
        }

        if (isset($filters['from']) && $event->occurredAt < $filters['from']) {
            return false;
        }

        if (isset($filters['to']) && $event->occurredAt >= $filters['to']) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function dateBucket(AdEvent $event, array $filters): string
    {
        return ($filters['granularity'] ?? 'day') === 'hour'
            ? $event->occurredAt->format('Y-m-d\TH:00:00P')
            : $event->occurredAt->format('Y-m-d');
    }
}
