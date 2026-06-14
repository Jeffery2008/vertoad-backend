<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Reporting\ConversionPathJourney;
use VertoAD\Domain\Reporting\ConversionPathTouchpoint;

final readonly class DatabaseConversionPathRepository implements ConversionPathRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findAttributedJourneys(array $filters): array
    {
        $maxTouchpoints = max(1, min(25, (int) ($filters['max_touchpoints'] ?? 10)));
        $conversions = $this->conversionRows($filters);
        $touchpointsByConversion = $this->touchpointsForConversions($conversions, $filters, $maxTouchpoints);
        $journeys = [];
        foreach ($conversions as $conversion) {
            $touchpoints = $touchpointsByConversion[(string) $conversion['conversion_event_id']] ?? [];
            if ($touchpoints === []) {
                continue;
            }

            $journeys[] = new ConversionPathJourney(
                conversionEventId: (string) $conversion['conversion_event_id'],
                conversionId: (string) $conversion['conversion_id'],
                conversionName: (string) $conversion['conversion_name'],
                source: (string) $conversion['source'],
                valuePoints: max(0, (int) $conversion['value_points']),
                occurredAt: $this->date((string) $conversion['conversion_occurred_at']),
                windowSeconds: max(1, (int) $conversion['window_seconds']),
                clickEventId: (string) $conversion['click_event_id'],
                viewerId: (string) $conversion['viewer_id'],
                campaignId: $conversion['campaign_id'] === null ? null : (int) $conversion['campaign_id'],
                advertiserOrganizationId: $conversion['advertiser_organization_id'] === null ? null : (int) $conversion['advertiser_organization_id'],
                publisherOrganizationId: $conversion['publisher_organization_id'] === null ? null : (int) $conversion['publisher_organization_id'],
                touchpoints: $touchpoints,
            );
        }

        return $journeys;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function conversionRows(array $filters): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(
                'c.event_id AS conversion_event_id',
                'c.conversion_id',
                'c.conversion_name',
                'c.source',
                'c.value_points',
                'c.occurred_at AS conversion_occurred_at',
                'c.window_seconds',
                'c.click_event_id',
                'COALESCE(c.campaign_id, click.campaign_id) AS campaign_id',
                'click.viewer_id',
                'click.advertiser_organization_id',
                'click.publisher_organization_id',
            )
            ->from('attribution_conversions', 'c')
            ->innerJoin('c', 'ad_serving_events', 'click', 'click.event_type = :click_type AND click.event_id = c.click_event_id')
            ->where('c.attributed = :attributed')
            ->andWhere('c.click_event_id IS NOT NULL')
            ->andWhere('click.valid = :valid')
            ->setParameter('click_type', 'click')
            ->setParameter('attributed', 1)
            ->setParameter('valid', 1);

        $this->applyOrganizationScope($query, 'click', $filters);
        if (isset($filters['campaign_id'])) {
            $query->andWhere('(c.campaign_id = :campaign_id OR (c.campaign_id IS NULL AND click.campaign_id = :click_campaign_id))')
                ->setParameter('campaign_id', (int) $filters['campaign_id'])
                ->setParameter('click_campaign_id', (int) $filters['campaign_id']);
        }
        foreach (['site_id', 'slot_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->andWhere('click.' . $filter . ' = :' . $filter)
                    ->setParameter($filter, (int) $filters[$filter]);
            }
        }
        if (isset($filters['from'])) {
            $query->andWhere('c.occurred_at >= :from_time')
                ->setParameter('from_time', $this->formatDate($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->andWhere('c.occurred_at < :to_time')
                ->setParameter('to_time', $this->formatDate($filters['to']));
        }

        $query->orderBy('c.occurred_at', 'DESC')
            ->addOrderBy('c.event_id', 'ASC');

        return $query->fetchAllAssociative();
    }

    /**
     * @param list<array<string, mixed>> $conversions
     * @param array<string, mixed> $filters
     * @return array<string, list<ConversionPathTouchpoint>>
     */
    private function touchpointsForConversions(array $conversions, array $filters, int $maxTouchpoints): array
    {
        if ($conversions === []) {
            return [];
        }

        $windowFloor = null;
        $windowCeiling = null;
        $viewerIds = [];
        foreach ($conversions as $conversion) {
            $occurredAt = $this->date((string) $conversion['conversion_occurred_at']);
            $windowStart = $occurredAt->modify('-' . max(1, (int) $conversion['window_seconds']) . ' seconds');
            $windowFloor = $windowFloor === null || $windowStart < $windowFloor ? $windowStart : $windowFloor;
            $windowCeiling = $windowCeiling === null || $occurredAt > $windowCeiling ? $occurredAt : $windowCeiling;
            $viewerIds[(string) $conversion['viewer_id']] = (string) $conversion['viewer_id'];
        }

        $query = $this->connection->createQueryBuilder()
            ->select(
                'e.viewer_id',
                'e.event_type',
                'e.event_id',
                'e.decision_id',
                'e.occurred_at',
                'e.campaign_id',
                'e.site_id',
                'e.slot_id',
            )
            ->from('ad_serving_events', 'e')
            ->where('e.valid = :valid')
            ->andWhere('e.viewer_id IN (:viewer_ids)')
            ->andWhere('e.event_type IN (:event_types)')
            ->andWhere('e.occurred_at >= :window_floor')
            ->andWhere('e.occurred_at <= :window_ceiling')
            ->setParameter('valid', 1)
            ->setParameter('viewer_ids', array_values($viewerIds), ArrayParameterType::STRING)
            ->setParameter('event_types', ['impression', 'click'], ArrayParameterType::STRING)
            ->setParameter('window_floor', $this->formatDate($windowFloor))
            ->setParameter('window_ceiling', $this->formatDate($windowCeiling));

        $this->applyOrganizationScope($query, 'e', $filters);

        $rows = $query->orderBy('e.occurred_at', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->fetchAllAssociative();

        $rowsByViewer = [];
        foreach ($rows as $row) {
            $rowsByViewer[(string) $row['viewer_id']][] = $row;
        }

        $touchpointsByConversion = [];
        foreach ($conversions as $conversion) {
            $occurredAt = $this->date((string) $conversion['conversion_occurred_at']);
            $windowStart = $occurredAt->modify('-' . max(1, (int) $conversion['window_seconds']) . ' seconds');
            $selectedRows = [];
            foreach ($rowsByViewer[(string) $conversion['viewer_id']] ?? [] as $row) {
                $touchpointOccurredAt = $this->date((string) $row['occurred_at']);
                if ($touchpointOccurredAt < $windowStart || $touchpointOccurredAt > $occurredAt) {
                    continue;
                }

                $selectedRows[] = $row;
                if (count($selectedRows) >= $maxTouchpoints) {
                    break;
                }
            }

            $touchpointsByConversion[(string) $conversion['conversion_event_id']] = $this->touchpointsFromRows(array_reverse($selectedRows));
        }

        return $touchpointsByConversion;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ConversionPathTouchpoint>
     */
    private function touchpointsFromRows(array $rows): array
    {
        return array_values(array_map(
            fn (array $row, int $index): ConversionPathTouchpoint => new ConversionPathTouchpoint(
                position: $index + 1,
                eventType: (string) $row['event_type'],
                eventId: (string) $row['event_id'],
                decisionId: (string) $row['decision_id'],
                occurredAt: $this->date((string) $row['occurred_at']),
                campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
                siteId: (int) $row['site_id'],
                slotId: (int) $row['slot_id'],
            ),
            $rows,
            array_keys($rows),
        ));
    }

    /**
     * @param \Doctrine\DBAL\Query\QueryBuilder $query
     * @param array<string, mixed> $filters
     */
    private function applyOrganizationScope(\Doctrine\DBAL\Query\QueryBuilder $query, string $alias, array $filters): void
    {
        if (!isset($filters['organization_id'])) {
            return;
        }

        $organizationId = (int) $filters['organization_id'];
        match ((string) ($filters['portal'] ?? 'advertiser')) {
            'publisher' => $query->andWhere($alias . '.publisher_organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId),
            default => $query->andWhere($alias . '.advertiser_organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId),
        };
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
