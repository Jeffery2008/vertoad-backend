<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Reporting\ConversionPathJourney;
use VertoAD\Domain\Reporting\ConversionPathTouchpoint;
use VertoAD\Repository\Reporting\ConversionPathRepositoryInterface;
use VertoAD\Service\Reporting\ConversionPathReportService;

final class ConversionPathReportServiceTest extends TestCase
{
    public function testAggregatesJourneysIntoStablePathSummary(): void
    {
        $service = new ConversionPathReportService(new StaticConversionPathRepository([
            $this->journey('conversion:1', 'order-1', 1_000, '2026-06-08T11:00:00+00:00'),
            $this->journey('conversion:2', 'order-2', 2_000, '2026-06-08T11:30:00+00:00'),
            $this->journey('conversion:3', 'order-3', 500, '2026-06-08T12:00:00+00:00', siteId: 11, slotId: 21),
        ]));

        $report = $service->conversionPaths([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
            'limit' => 1,
            'max_touchpoints' => 10,
        ]);

        self::assertSame('advertiser', $report['portal']);
        self::assertSame(40, $report['organization_id']);
        self::assertSame('conversion', $report['range']['granularity']);
        self::assertSame(3, $report['summary']['conversions']);
        self::assertSame(3, $report['summary']['attributed_conversions']);
        self::assertSame(3_500, $report['summary']['conversion_value_points']);
        self::assertSame(2.0, $report['summary']['avg_touchpoints']);
        self::assertSame(3600.0, $report['summary']['avg_time_to_convert_seconds']);
        self::assertCount(1, $report['paths']);
        self::assertSame('impression:c30:s10:p20>click:c30:s10:p20>conversion', $report['paths'][0]['path_key']);
        self::assertSame(2, $report['paths'][0]['conversions']);
        self::assertSame(3_000, $report['paths'][0]['conversion_value_points']);
        self::assertSame(2.0, $report['paths'][0]['avg_touchpoints']);
        self::assertSame(3600.0, $report['paths'][0]['avg_time_to_convert_seconds']);
        self::assertSame('impression', $report['paths'][0]['touchpoints'][0]['event_type']);
        self::assertSame('imp-conversion:1', $report['paths'][0]['touchpoints'][0]['event_id']);
        self::assertSame('click', $report['paths'][0]['touchpoints'][1]['event_type']);
    }

    public function testDefaultsRangeAndEmptySummaryWhenNoJourneysExist(): void
    {
        $service = new ConversionPathReportService(
            new StaticConversionPathRepository([]),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-10T12:00:00+00:00'),
        );

        $report = $service->conversionPaths([
            'portal' => 'publisher',
            'organization_id' => 50,
        ]);

        self::assertSame('publisher', $report['portal']);
        self::assertSame(50, $report['organization_id']);
        self::assertSame('2026-06-03T12:00:00+00:00', $report['range']['from']);
        self::assertSame('2026-06-10T12:00:00+00:00', $report['range']['to']);
        self::assertSame(0, $report['summary']['conversions']);
        self::assertSame(0.0, $report['summary']['avg_touchpoints']);
        self::assertSame([], $report['paths']);
    }

    public function testCompletesPartialRangesAndSupportsConversionOnlyPath(): void
    {
        $service = new ConversionPathReportService(new StaticConversionPathRepository([
            new ConversionPathJourney(
                conversionEventId: 'conversion:direct',
                conversionId: 'direct-order',
                conversionName: 'purchase',
                source: 'server_api',
                valuePoints: 700,
                occurredAt: new DateTimeImmutable('2026-06-08T11:00:00+00:00'),
                windowSeconds: 7200,
                clickEventId: 'clk-direct',
                viewerId: 'viewer-direct',
                campaignId: null,
                advertiserOrganizationId: null,
                publisherOrganizationId: null,
                touchpoints: [],
            ),
        ]));

        $fromOnly = $service->conversionPaths([
            'from' => new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        ]);
        $toOnly = $service->conversionPaths([
            'to' => new DateTimeImmutable('2026-06-15T10:00:00+00:00'),
        ]);

        self::assertSame('2026-06-08T10:00:00+00:00', $fromOnly['range']['from']);
        self::assertSame('2026-06-15T10:00:00+00:00', $fromOnly['range']['to']);
        self::assertSame('2026-06-08T10:00:00+00:00', $toOnly['range']['from']);
        self::assertSame('2026-06-15T10:00:00+00:00', $toOnly['range']['to']);
        self::assertSame('conversion', $fromOnly['paths'][0]['path_key']);
        self::assertSame(0.0, $fromOnly['paths'][0]['avg_touchpoints']);
        self::assertSame(0.0, $fromOnly['paths'][0]['avg_time_to_convert_seconds']);
    }

    public function testDomainObjectsRejectInvalidConversionPathData(): void
    {
        foreach ([
            'touchpoint position must be positive.' => fn () => new ConversionPathTouchpoint(0, 'impression', 'event-1', 'decision-1', new DateTimeImmutable(), 30, 10, 20),
            'touchpoint event_type must be impression or click.' => fn () => new ConversionPathTouchpoint(1, 'view', 'event-1', 'decision-1', new DateTimeImmutable(), 30, 10, 20),
            'event_id is required.' => fn () => new ConversionPathTouchpoint(1, 'impression', '', 'decision-1', new DateTimeImmutable(), 30, 10, 20),
            'campaign_id must be positive when present.' => fn () => new ConversionPathTouchpoint(1, 'impression', 'event-1', 'decision-1', new DateTimeImmutable(), 0, 10, 20),
            'conversion_event_id is required.' => fn () => new ConversionPathJourney('', 'conversion-1', 'purchase', 'server_api', 0, new DateTimeImmutable(), 1, 'click-1', 'viewer-1', null, null, null, []),
            'conversion value_points cannot be negative.' => fn () => new ConversionPathJourney('event-1', 'conversion-1', 'purchase', 'server_api', -1, new DateTimeImmutable(), 1, 'click-1', 'viewer-1', null, null, null, []),
            'conversion window_seconds must be positive.' => fn () => new ConversionPathJourney('event-1', 'conversion-1', 'purchase', 'server_api', 0, new DateTimeImmutable(), 0, 'click-1', 'viewer-1', null, null, null, []),
            'advertiser_organization_id must be positive when present.' => fn () => new ConversionPathJourney('event-1', 'conversion-1', 'purchase', 'server_api', 0, new DateTimeImmutable(), 1, 'click-1', 'viewer-1', null, 0, null, []),
            'touchpoints must contain ConversionPathTouchpoint instances.' => fn () => new ConversionPathJourney('event-1', 'conversion-1', 'purchase', 'server_api', 0, new DateTimeImmutable(), 1, 'click-1', 'viewer-1', null, null, null, ['bad']),
            'touchpoints must use contiguous positions.' => fn () => new ConversionPathJourney('event-1', 'conversion-1', 'purchase', 'server_api', 0, new DateTimeImmutable(), 1, 'click-1', 'viewer-1', null, null, null, [
                new ConversionPathTouchpoint(2, 'click', 'click-1', 'decision-1', new DateTimeImmutable(), null, 10, 20),
            ]),
        ] as $message => $factory) {
            try {
                $factory();
                self::fail('Expected invalid conversion path data: ' . $message);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    private function journey(
        string $conversionEventId,
        string $conversionId,
        int $valuePoints,
        string $conversionOccurredAt,
        int $siteId = 10,
        int $slotId = 20,
    ): ConversionPathJourney {
        $base = new DateTimeImmutable($conversionOccurredAt);

        return new ConversionPathJourney(
            conversionEventId: $conversionEventId,
            conversionId: $conversionId,
            conversionName: 'purchase',
            source: 'server_api',
            valuePoints: $valuePoints,
            occurredAt: $base,
            windowSeconds: 7200,
            clickEventId: 'clk-' . $conversionEventId,
            viewerId: 'viewer-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            touchpoints: [
                new ConversionPathTouchpoint(
                    position: 1,
                    eventType: 'impression',
                    eventId: 'imp-' . $conversionEventId,
                    decisionId: 'decision-' . $conversionEventId,
                    occurredAt: $base->modify('-1 hour'),
                    campaignId: 30,
                    siteId: $siteId,
                    slotId: $slotId,
                ),
                new ConversionPathTouchpoint(
                    position: 2,
                    eventType: 'click',
                    eventId: 'clk-' . $conversionEventId,
                    decisionId: 'decision-' . $conversionEventId,
                    occurredAt: $base->modify('-30 minutes'),
                    campaignId: 30,
                    siteId: $siteId,
                    slotId: $slotId,
                ),
            ],
        );
    }
}

final readonly class StaticConversionPathRepository implements ConversionPathRepositoryInterface
{
    /**
     * @param list<ConversionPathJourney> $journeys
     */
    public function __construct(private array $journeys)
    {
    }

    public function findAttributedJourneys(array $filters): array
    {
        return $this->journeys;
    }
}
