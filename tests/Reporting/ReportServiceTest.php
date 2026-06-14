<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Reporting\ReportAggregateRow;
use VertoAD\Repository\Reporting\StaticReportAggregateRepository;
use VertoAD\Service\Reporting\ReportQueryService;

final class ReportServiceTest extends TestCase
{
    public function testReturnsDashboardSummarySeriesAndExtensionDimensions(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([
            new ReportAggregateRow('2026-06-08', 99, 123, 5, 10, 'cn-sh', 'desktop', 'chrome', '1920x1080', 'low', 100, 25, 1_000, 600),
            new ReportAggregateRow('2026-06-09', 99, 123, 5, 10, 'cn-sh', 'mobile', 'safari', '390x844', 'medium', 0, 2, 80, 50),
        ]));

        $report = $service->dashboard([
            'portal' => 'advertiser',
            'organization_id' => 99,
            'granularity' => 'day',
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-10T00:00:00+00:00'),
        ]);

        self::assertSame('advertiser', $report['portal']);
        self::assertSame(99, $report['organization_id']);
        self::assertSame('2026-06-08T00:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-10T00:00:00+00:00', $report['range']['to']);
        self::assertSame('day', $report['range']['granularity']);
        self::assertSame(100, $report['totals']['impressions']);
        self::assertSame(27, $report['totals']['clicks']);
        self::assertSame(27.0, $report['totals']['ctr']);
        self::assertSame(1_080, $report['totals']['spend_points']);
        self::assertSame(650, $report['totals']['revenue_points']);
        self::assertCount(2, $report['series']);
        self::assertSame('2026-06-08', $report['series'][0]['date']);
        self::assertSame(25.0, $report['series'][0]['ctr']);
        self::assertSame('Cn Sh', $report['dimensions']['geo'][0]['label']);
        self::assertSame(100, $report['dimensions']['geo'][0]['impressions']);
        self::assertSame(27.0, $report['dimensions']['geo'][0]['ctr']);
        self::assertSame(10, $report['dimensions']['risk'][0]['risk_score']);
        self::assertSame(50, $report['dimensions']['risk'][1]['risk_score']);
    }

    public function testMapsHighAndUnknownRiskBuckets(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([
            new ReportAggregateRow('2026-06-08', 99, 123, 5, 10, null, null, null, null, 'high', 10, 1, 100, 50),
            new ReportAggregateRow('2026-06-09', 99, 123, 5, 10, null, null, null, null, 'review', 10, 1, 100, 50),
        ]));

        $report = $service->dashboard([]);

        self::assertSame(90, $report['dimensions']['risk'][0]['risk_score']);
        self::assertSame(0, $report['dimensions']['risk'][1]['risk_score']);
    }

    public function testSeriesAggregatesMultipleDimensionRowsIntoOnePointPerBucket(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([
            new ReportAggregateRow('2026-06-08', 99, 123, 5, 10, 'cn-sh', 'desktop', null, null, 'low', 10, 1, 100, 60),
            new ReportAggregateRow('2026-06-08', 99, 123, 5, 10, 'cn-bj', 'mobile', null, null, 'medium', 20, 3, 300, 180),
            new ReportAggregateRow('2026-06-09', 99, 123, 5, 10, 'cn-sh', 'desktop', null, null, 'low', 5, 1, 40, 24),
        ]));

        $report = $service->dashboard(['granularity' => 'day']);

        self::assertCount(2, $report['series']);
        self::assertSame('2026-06-08', $report['series'][0]['date']);
        self::assertSame(30, $report['series'][0]['impressions']);
        self::assertSame(4, $report['series'][0]['clicks']);
        self::assertSame(400, $report['series'][0]['spend_points']);
        self::assertSame(240, $report['series'][0]['revenue_points']);
        self::assertEqualsWithDelta(13.3333, $report['series'][0]['ctr'], 0.0001);
    }

    public function testDefaultsRangeWhenFiltersAndRowsAreAbsent(): void
    {
        $service = new ReportQueryService(
            new StaticReportAggregateRepository([]),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-10T12:00:00+00:00'),
        );

        $report = $service->dashboard([]);

        self::assertSame('admin', $report['portal']);
        self::assertNull($report['organization_id']);
        self::assertSame('2026-06-03T12:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-10T12:00:00+00:00', $report['range']['to']);
        self::assertSame('day', $report['range']['granularity']);
        self::assertSame(0.0, $report['totals']['ctr']);
        self::assertSame([], $report['dimensions']['geo']);
    }

    public function testDefaultsRangeFromRowsAsRfc3339DateTimes(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([
            new ReportAggregateRow('2026-06-08', null, null, 5, 10, null, null, null, null, null, 100, 25, 1_000, 600),
            new ReportAggregateRow('2026-06-09', null, null, 5, 10, null, null, null, null, null, 40, 8, 300, 180),
        ]));

        $report = $service->dashboard(['granularity' => 'day']);

        self::assertSame('2026-06-08T00:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-10T00:00:00+00:00', $report['range']['to']);
    }

    public function testDefaultsHourlyRangeFromRowsAsRfc3339DateTimes(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([
            new ReportAggregateRow('2026-06-08T10:00:00+00:00', null, null, 5, 10, null, null, null, null, null, 100, 25, 1_000, 600),
            new ReportAggregateRow('2026-06-08T11:00:00+00:00', null, null, 5, 10, null, null, null, null, null, 40, 8, 300, 180),
        ]));

        $report = $service->dashboard(['granularity' => 'hour']);

        self::assertSame('2026-06-08T10:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-08T12:00:00+00:00', $report['range']['to']);
    }

    public function testRangeUsesProvidedFiltersWhenRowsAreAbsent(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([]));

        $report = $service->dashboard([
            'granularity' => 'hour',
            'from' => new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
        ]);

        self::assertSame('2026-06-08T10:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-08T12:00:00+00:00', $report['range']['to']);
        self::assertSame([], $report['series']);
    }

    public function testRangeCompletesPartialFiltersWhenRowsAreAbsent(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([]));

        $reportWithFromOnly = $service->dashboard([
            'from' => new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        ]);
        $reportWithToOnly = $service->dashboard([
            'to' => new DateTimeImmutable('2026-06-15T10:00:00+00:00'),
        ]);

        self::assertSame('2026-06-08T10:00:00+00:00', $reportWithFromOnly['range']['from']);
        self::assertSame('2026-06-15T10:00:00+00:00', $reportWithFromOnly['range']['to']);
        self::assertSame('2026-06-08T10:00:00+00:00', $reportWithToOnly['range']['from']);
        self::assertSame('2026-06-15T10:00:00+00:00', $reportWithToOnly['range']['to']);
    }

    public function testDefaultsRangeFromCurrentTimeWhenClockIsNotInjected(): void
    {
        $service = new ReportQueryService(new StaticReportAggregateRepository([]));

        $report = $service->dashboard([]);
        $from = new DateTimeImmutable($report['range']['from']);
        $to = new DateTimeImmutable($report['range']['to']);

        self::assertSame(604_800, $to->getTimestamp() - $from->getTimestamp());
    }
}
