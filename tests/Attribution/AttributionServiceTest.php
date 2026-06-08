<?php

declare(strict_types=1);

namespace VertoAD\Tests\Attribution;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Attribution\InMemoryAttributionEventRepository;
use VertoAD\Service\Attribution\AttributionService;

final class AttributionServiceTest extends TestCase
{
    public function testServerApiConversionUsesMostRecentValidClickWithinWindow(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision('old-click', 10), 'click-old', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $repository->recordClick($this->decision('last-click', 20), 'click-last', new DateTimeImmutable('2026-06-08T11:00:00+00:00'));
        $service = new AttributionService($repository, defaultWindowSeconds: 86400);

        $result = $service->recordServerApiConversion([
            'event_id' => 'conv-1',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
            'value_points' => 1234,
            'occurred_at' => '2026-06-08T12:00:00+00:00',
        ]);

        self::assertTrue($result->attributed);
        self::assertFalse($result->duplicate);
        self::assertSame('click-last', $result->clickEventId);
        self::assertSame('last-click', $result->decisionId);
        self::assertSame(20, $result->campaignId);
        self::assertSame(86400, $result->windowSeconds);
    }

    public function testConversionWindowCanBeOverriddenAndRejectsExpiredClicks(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision('click-1', 10), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $service = new AttributionService($repository, defaultWindowSeconds: 86400);

        $result = $service->recordServerApiConversion([
            'event_id' => 'conv-2',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'signup',
            'occurred_at' => '2026-06-08T10:10:01+00:00',
            'window_seconds' => 600,
        ]);

        self::assertFalse($result->attributed);
        self::assertNull($result->clickEventId);
        self::assertSame(600, $result->windowSeconds);
    }

    public function testConversionEventIdIsIdempotent(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision('click-1', 10), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $service = new AttributionService($repository, defaultWindowSeconds: 86400);

        $first = $service->recordServerApiConversion([
            'event_id' => 'conv-3',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'lead',
            'occurred_at' => '2026-06-08T11:00:00+00:00',
        ]);
        $second = $service->recordServerApiConversion([
            'event_id' => 'conv-3',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'lead',
            'occurred_at' => '2026-06-08T11:05:00+00:00',
        ]);

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame($first->conversionId, $second->conversionId);
    }

    public function testInvalidPayloadIsRejectedBeforeRecording(): void
    {
        $service = new AttributionService(new InMemoryAttributionEventRepository(), defaultWindowSeconds: 86400);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('viewer_id must be a non-empty string.');

        $service->recordServerApiConversion([
            'event_id' => 'conv-4',
            'viewer_id' => '',
            'conversion_name' => 'purchase',
        ]);
    }

    public function testDefaultOccurredAtCanRecordUnattributedConversion(): void
    {
        $service = new AttributionService(new InMemoryAttributionEventRepository(), defaultWindowSeconds: 86400);

        $result = $service->recordServerApiConversion([
            'event_id' => 'conv-default-time',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
        ]);

        self::assertFalse($result->attributed);
        self::assertSame(0, $result->valuePoints);
        self::assertSame(86400, $result->windowSeconds);
    }

    public function testInvalidNumericAndDatePayloadsAreRejected(): void
    {
        $service = new AttributionService(new InMemoryAttributionEventRepository(), defaultWindowSeconds: 86400);

        try {
            $service->recordServerApiConversion([
                'event_id' => 'conv-bad-value',
                'viewer_id' => 'viewer-1',
                'conversion_name' => 'purchase',
                'value_points' => -1,
            ]);
            self::fail('Expected value_points validation to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('value_points must be a non-negative integer.', $exception->getMessage());
        }

        try {
            $service->recordServerApiConversion([
                'event_id' => 'conv-bad-date-type',
                'viewer_id' => 'viewer-1',
                'conversion_name' => 'purchase',
                'occurred_at' => [],
            ]);
            self::fail('Expected occurred_at type validation to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('occurred_at must be an ISO-8601 date-time string.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('occurred_at must be an ISO-8601 date-time string.');

        $service->recordServerApiConversion([
            'event_id' => 'conv-bad-date',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
            'occurred_at' => 'not-a-date',
        ]);
    }

    private function decision(string $decisionId, int $campaignId): AdDecision
    {
        return new AdDecision(
            decisionId: $decisionId,
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: '<iframe></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-' . $campaignId,
            campaignId: $campaignId,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: new DateTimeImmutable('2026-06-08T09:00:00+00:00'),
        );
    }
}
