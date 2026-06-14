<?php

declare(strict_types=1);

namespace VertoAD\Service\Reporting;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\Reporting\ConversionPathJourney;
use VertoAD\Domain\Reporting\ConversionPathTouchpoint;
use VertoAD\Repository\Reporting\ConversionPathRepositoryInterface;

final readonly class ConversionPathReportService
{
    public function __construct(
        private ConversionPathRepositoryInterface $paths,
        private ?Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function conversionPaths(array $filters): array
    {
        $filters = $this->withRangeDefaults($filters);
        $limit = (int) ($filters['limit'] ?? 20);
        $journeys = $this->paths->findAttributedJourneys($filters);
        $groups = [];
        $summary = [
            'conversions' => 0,
            'attributed_conversions' => 0,
            'conversion_value_points' => 0,
            'touchpoints' => 0,
            'time_to_convert_seconds' => 0,
        ];

        foreach ($journeys as $journey) {
            $pathKey = $this->pathKey($journey);
            $touchpointCount = count($journey->touchpoints);
            $timeToConvertSeconds = $this->timeToConvertSeconds($journey);
            $summary['conversions']++;
            $summary['attributed_conversions']++;
            $summary['conversion_value_points'] += $journey->valuePoints;
            $summary['touchpoints'] += $touchpointCount;
            $summary['time_to_convert_seconds'] += $timeToConvertSeconds;

            $groups[$pathKey] ??= [
                'path_key' => $pathKey,
                'conversions' => 0,
                'conversion_value_points' => 0,
                'touchpoints_total' => 0,
                'time_to_convert_seconds_total' => 0,
                'touchpoints' => $this->serializeTouchpoints($journey->touchpoints),
            ];
            $groups[$pathKey]['conversions']++;
            $groups[$pathKey]['conversion_value_points'] += $journey->valuePoints;
            $groups[$pathKey]['touchpoints_total'] += $touchpointCount;
            $groups[$pathKey]['time_to_convert_seconds_total'] += $timeToConvertSeconds;
        }

        $pathBuckets = array_values(array_map(
            fn (array $group): array => [
                'path_key' => $group['path_key'],
                'conversions' => $group['conversions'],
                'conversion_value_points' => $group['conversion_value_points'],
                'avg_touchpoints' => $this->average($group['touchpoints_total'], $group['conversions']),
                'avg_time_to_convert_seconds' => $this->average($group['time_to_convert_seconds_total'], $group['conversions']),
                'touchpoints' => $group['touchpoints'],
            ],
            $groups,
        ));

        usort($pathBuckets, static fn (array $left, array $right): int => [
            -$left['conversions'],
            -$left['conversion_value_points'],
            $left['path_key'],
        ] <=> [
            -$right['conversions'],
            -$right['conversion_value_points'],
            $right['path_key'],
        ]);

        return [
            'portal' => $filters['portal'] ?? 'advertiser',
            'organization_id' => $filters['organization_id'] ?? null,
            'range' => [
                'from' => $filters['from']->format(DATE_ATOM),
                'to' => $filters['to']->format(DATE_ATOM),
                'granularity' => 'conversion',
            ],
            'summary' => [
                'conversions' => $summary['conversions'],
                'attributed_conversions' => $summary['attributed_conversions'],
                'conversion_value_points' => $summary['conversion_value_points'],
                'avg_touchpoints' => $this->average($summary['touchpoints'], $summary['conversions']),
                'avg_time_to_convert_seconds' => $this->average($summary['time_to_convert_seconds'], $summary['conversions']),
            ],
            'paths' => array_slice($pathBuckets, 0, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function withRangeDefaults(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if (!$from instanceof DateTimeImmutable && !$to instanceof DateTimeImmutable) {
            $to = $this->now();
            $from = $to->modify('-7 days');
        } elseif (!$from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
            $from = $to->modify('-7 days');
        } elseif ($from instanceof DateTimeImmutable && !$to instanceof DateTimeImmutable) {
            $to = $from->modify('+7 days');
        }

        $filters['from'] = $from;
        $filters['to'] = $to;
        $filters['limit'] = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $filters['max_touchpoints'] = max(1, min(25, (int) ($filters['max_touchpoints'] ?? 10)));

        return $filters;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function pathKey(ConversionPathJourney $journey): string
    {
        $stages = array_map(
            static fn (ConversionPathTouchpoint $touchpoint): string => sprintf(
                '%s:c%s:s%d:p%d',
                $touchpoint->eventType,
                $touchpoint->campaignId === null ? 'none' : (string) $touchpoint->campaignId,
                $touchpoint->siteId,
                $touchpoint->slotId,
            ),
            $journey->touchpoints,
        );
        $stages[] = 'conversion';

        return implode('>', $stages);
    }

    private function timeToConvertSeconds(ConversionPathJourney $journey): int
    {
        if ($journey->touchpoints === []) {
            return 0;
        }

        return max(0, $journey->occurredAt->getTimestamp() - $journey->touchpoints[0]->occurredAt->getTimestamp());
    }

    /**
     * @param list<ConversionPathTouchpoint> $touchpoints
     * @return list<array<string, int|string>>
     */
    private function serializeTouchpoints(array $touchpoints): array
    {
        return array_map(
            static fn (ConversionPathTouchpoint $touchpoint): array => [
                'position' => $touchpoint->position,
                'event_type' => $touchpoint->eventType,
                'event_id' => $touchpoint->eventId,
                'decision_id' => $touchpoint->decisionId,
                'occurred_at' => $touchpoint->occurredAt->format(DATE_ATOM),
                'campaign_id' => $touchpoint->campaignId,
                'site_id' => $touchpoint->siteId,
                'slot_id' => $touchpoint->slotId,
            ],
            $touchpoints,
        );
    }

    private function average(int $total, int $count): float
    {
        if ($count === 0) {
            return 0.0;
        }

        return round($total / $count, 4);
    }
}
