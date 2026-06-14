<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Reporting\ReportAggregateRow;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Repository\Reporting\InMemoryReportAggregateRepository;

final class ReportAggregateRepositoryTest extends TestCase
{
    public function testAggregatesValidServingEventsByDayAndFilters(): void
    {
        $repository = new InMemoryReportAggregateRepository([
            $this->event('impression', 'imp-1', 99, 42, 123, 5, 10, 40, '2026-06-08 10:00:00'),
            $this->event('click', 'clk-1', 99, 42, 123, 5, 10, 80, '2026-06-08 10:01:00'),
            $this->event('impression', 'imp-2', 99, 42, 124, 5, 11, 30, '2026-06-08 11:00:00'),
            $this->event('click', 'invalid-click', 99, 42, 123, 5, 10, 80, '2026-06-08 11:01:00', false),
            $this->event('viewable', 'ignored-viewable', 99, 42, 123, 5, 10, 80, '2026-06-08 11:02:00'),
            $this->event('click', 'other-org-click', 100, 43, 123, 6, 12, 90, '2026-06-08 12:00:00'),
        ]);

        $rows = $repository->query([
            'organization_id' => 99,
            'campaign_id' => 123,
            'site_id' => 5,
            'slot_id' => 10,
            'from' => new DateTimeImmutable('2026-06-08 00:00:00'),
            'to' => new DateTimeImmutable('2026-06-09 00:00:00'),
        ]);

        self::assertCount(1, $rows);
        self::assertSame('2026-06-08', $rows[0]->date);
        self::assertSame(99, $rows[0]->organizationId);
        self::assertSame(123, $rows[0]->campaignId);
        self::assertSame(5, $rows[0]->siteId);
        self::assertSame(10, $rows[0]->slotId);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(1, $rows[0]->clicks);
        self::assertSame(40, $rows[0]->spendPoints);
        self::assertSame(80, $rows[0]->revenuePoints);
        self::assertSame(0, $rows[0]->conversions);
        self::assertSame(0, $rows[0]->conversionValuePoints);
        self::assertNull($rows[0]->geo);
        self::assertNull($rows[0]->device);
        self::assertNull($rows[0]->browser);
        self::assertNull($rows[0]->resolution);
        self::assertNull($rows[0]->riskBucket);
    }

    public function testUsesPublisherOrganizationFilterForRevenueSideEvents(): void
    {
        $repository = new InMemoryReportAggregateRepository([
            $this->event('impression', 'imp-1', 99, 42, 123, 5, 10, 40, '2026-06-08 10:00:00'),
            $this->event('click', 'clk-1', 100, 42, 124, 5, 10, 80, '2026-06-08 10:01:00'),
        ]);

        $rows = $repository->query([
            'organization_id' => 42,
            'from' => new DateTimeImmutable('2026-06-08 00:00:00'),
            'to' => new DateTimeImmutable('2026-06-09 00:00:00'),
        ]);

        self::assertCount(2, $rows);
        self::assertSame([123, 124], array_map(static fn ($row): int => $row->campaignId, $rows));
    }

    public function testAcceptsCallableSourceAndHourGranularity(): void
    {
        $repository = new InMemoryReportAggregateRepository(fn (): array => [
            $this->event('impression', 'imp-1', 99, 42, 123, 5, 10, 40, '2026-06-08T10:00:00+00:00'),
            $this->event('impression', 'imp-2', 99, 42, 123, 5, 10, 40, '2026-06-08T11:00:00+00:00'),
        ]);

        $rows = $repository->query(['granularity' => 'hour']);

        self::assertCount(2, $rows);
        self::assertSame('2026-06-08T10:00:00+00:00', $rows[0]->date);
        self::assertSame('2026-06-08T11:00:00+00:00', $rows[1]->date);
    }

    public function testExcludesEventsOutsideDateRange(): void
    {
        $repository = new InMemoryReportAggregateRepository([
            $this->event('impression', 'before', 99, 42, 123, 5, 10, 40, '2026-06-07T23:59:59+00:00'),
            $this->event('impression', 'inside', 99, 42, 123, 5, 10, 40, '2026-06-08T12:00:00+00:00'),
            $this->event('impression', 'after', 99, 42, 123, 5, 10, 40, '2026-06-09T00:00:00+00:00'),
        ]);

        $rows = $repository->query([
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->impressions);
    }

    public function testRejectsInvalidEventSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report event source must be an array or callable.');

        new InMemoryReportAggregateRepository('events');
    }

    public function testAggregateRowValidatesRequiredAndPositiveFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report aggregate date is required.');

        new ReportAggregateRow('', null, null, null, null, null, null, null, null, null, 0, 0, 0, 0);
    }

    public function testAggregateRowRejectsInvalidIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('campaign_id must be positive when present.');

        new ReportAggregateRow('2026-06-08', null, 0, null, null, null, null, null, null, null, 0, 0, 0, 0);
    }

    public function testAggregateRowRejectsNegativeMetrics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conversion_value_points cannot be negative.');

        new ReportAggregateRow('2026-06-08', null, null, null, null, null, null, null, null, null, 0, 0, 0, 0, 0, -1);
    }

    private function event(
        string $type,
        string $id,
        int $advertiserOrganizationId,
        int $publisherOrganizationId,
        int $campaignId,
        int $siteId,
        int $slotId,
        int $costPoints,
        string $occurredAt,
        bool $valid = true,
    ): AdEvent {
        return new AdEvent(
            eventType: $type,
            eventId: $id,
            decisionId: 'decision-' . $id,
            siteId: $siteId,
            slotId: $slotId,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: $campaignId,
            advertiserOrganizationId: $advertiserOrganizationId,
            publisherOrganizationId: $publisherOrganizationId,
            costPoints: $costPoints,
            occurredAt: new DateTimeImmutable($occurredAt),
            valid: $valid,
            reason: $valid ? null : 'fraud_rejected',
        );
    }
}
