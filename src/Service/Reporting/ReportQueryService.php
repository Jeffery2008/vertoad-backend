<?php

declare(strict_types=1);

namespace VertoAD\Service\Reporting;

use VertoAD\Domain\Reporting\ReportAggregateRow;
use VertoAD\Repository\Reporting\ReportAggregateRepositoryInterface;

final readonly class ReportQueryService
{
    public function __construct(private ReportAggregateRepositoryInterface $aggregates)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters): array
    {
        $rows = $this->aggregates->query($filters);
        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'spendPoints' => 0,
            'revenuePoints' => 0,
        ];

        foreach ($rows as $row) {
            $totals['impressions'] += $row->impressions;
            $totals['clicks'] += $row->clicks;
            $totals['spendPoints'] += $row->spendPoints;
            $totals['revenuePoints'] += $row->revenuePoints;
        }

        return [
            'portal' => $filters['portal'] ?? 'admin',
            'organization_id' => $filters['organization_id'] ?? null,
            'range' => $this->range($filters, $rows),
            'totals' => $this->totals($totals['impressions'], $totals['clicks'], $totals['spendPoints'], $totals['revenuePoints']),
            'series' => array_map(fn (ReportAggregateRow $row): array => $this->row($row), $rows),
            'dimensions' => $this->dimensions($rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReportAggregateRow $row): array
    {
        return [
            'date' => $row->date,
            ...$this->totals($row->impressions, $row->clicks, $row->spendPoints, $row->revenuePoints),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<ReportAggregateRow> $rows
     * @return array{from:string,to:string,granularity:string}
     */
    private function range(array $filters, array $rows): array
    {
        return [
            'from' => isset($filters['from']) ? $filters['from']->format(DATE_ATOM) : ($rows[0]->date ?? ''),
            'to' => isset($filters['to']) ? $filters['to']->format(DATE_ATOM) : ($rows === [] ? '' : $rows[array_key_last($rows)]->date),
            'granularity' => $filters['granularity'] ?? 'day',
        ];
    }

    /**
     * @return array{impressions:int,clicks:int,ctr:float,spend_points:int,revenue_points:int}
     */
    private function totals(int $impressions, int $clicks, int $spendPoints, int $revenuePoints): array
    {
        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $this->ctr($clicks, $impressions),
            'spend_points' => $spendPoints,
            'revenue_points' => $revenuePoints,
        ];
    }

    /**
     * @param list<ReportAggregateRow> $rows
     * @return array<string, list<array<string, int|string|float>>>
     */
    private function dimensions(array $rows): array
    {
        $dimensions = [
            'geo' => [],
            'device' => [],
            'browser' => [],
            'resolution' => [],
            'risk' => [],
        ];

        foreach ($rows as $row) {
            foreach ([
                'geo' => $row->geo,
                'device' => $row->device,
                'browser' => $row->browser,
                'resolution' => $row->resolution,
                'risk' => $row->riskBucket,
            ] as $dimension => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $dimensions[$dimension][$value] ??= [
                    'key' => $value,
                    'label' => $this->dimensionLabel($value),
                    'impressions' => 0,
                    'clicks' => 0,
                    'risk_score' => $dimension === 'risk' ? $this->riskScore($value) : 0,
                ];
                $dimensions[$dimension][$value]['impressions'] += $row->impressions;
                $dimensions[$dimension][$value]['clicks'] += $row->clicks;
            }
        }

        foreach ($dimensions as $dimension => $buckets) {
            $dimensions[$dimension] = array_values(array_map(
                fn (array $bucket): array => [
                    ...$bucket,
                    'ctr' => $this->ctr($bucket['clicks'], $bucket['impressions']),
                ],
                $buckets,
            ));
        }

        return $dimensions;
    }

    private function ctr(int $clicks, int $impressions): float
    {
        if ($impressions === 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 4);
    }

    private function dimensionLabel(string $value): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $value));
    }

    private function riskScore(string $value): int
    {
        return match ($value) {
            'low' => 10,
            'medium' => 50,
            'high' => 90,
            default => 0,
        };
    }
}
