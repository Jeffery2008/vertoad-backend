<?php

declare(strict_types=1);

namespace VertoAD\Tests\Attribution;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Attribution\ConversionAttributionResult;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Attribution\DatabaseAttributionEventRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Tests\Serving\DatabaseServingPersistenceRepositoryTest;

final class DatabaseAttributionEventRepositoryTest extends TestCase
{
    public function testFindLastClickUsesValidServingClicksAcrossRepositoryInstances(): void
    {
        $connection = $this->createConnection();
        $oldDecision = $this->decision('decision-old', 100);
        $lastDecision = $this->decision('decision-last', 200);
        $otherViewerDecision = $this->decision('decision-other-viewer', 300, viewerId: 'viewer-2');
        $decisions = new DatabaseAdDecisionRepository($connection);
        $decisions->save($oldDecision);
        $decisions->save($lastDecision);
        $decisions->save($otherViewerDecision);

        $events = new DatabaseAdEventRepository($connection);
        $events->recordClick($oldDecision, 'click-old', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $events->recordInvalidClick($lastDecision, 'click-invalid', new DateTimeImmutable('2026-06-08T10:30:00+00:00'), 'repeat_click_window');
        $events->recordClick($lastDecision, 'click-last', new DateTimeImmutable('2026-06-08T11:00:00+00:00'));
        $events->recordClick($otherViewerDecision, 'click-other-viewer', new DateTimeImmutable('2026-06-08T11:30:00+00:00'));

        $click = (new DatabaseAttributionEventRepository($connection))->findLastClick(
            'viewer-1',
            new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
            86400,
        );

        self::assertNotNull($click);
        self::assertSame('click-last', $click['click_event_id']);
        self::assertSame('decision-last', $click['decision']->decisionId);
        self::assertSame(200, $click['decision']->campaignId);
        self::assertSame((new DateTimeImmutable('2026-06-08T11:00:00+00:00'))->getTimestamp(), $click['occurred_at']->getTimestamp());
    }

    public function testFindLastClickHonorsAttributionWindow(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision('decision-window', 100);
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        (new DatabaseAdEventRepository($connection))->recordClick(
            $decision,
            'click-window',
            new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        );

        $click = (new DatabaseAttributionEventRepository($connection))->findLastClick(
            'viewer-1',
            new DateTimeImmutable('2026-06-08T10:10:01+00:00'),
            600,
        );

        self::assertNull($click);
    }

    public function testRecordClickWritesServingClickWhenUsedAsAttributionSeed(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision('decision-seeded', 100);
        $repository = new DatabaseAttributionEventRepository($connection);

        $repository->recordClick($decision, 'click-seeded', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));

        $click = (new DatabaseAttributionEventRepository($connection))->findLastClick(
            'viewer-1',
            new DateTimeImmutable('2026-06-08T11:00:00+00:00'),
            86400,
        );

        self::assertNotNull($click);
        self::assertSame('click-seeded', $click['click_event_id']);
        self::assertSame('decision-seeded', $click['decision']->decisionId);
    }

    public function testConversionsSurviveRepositoryInstancesAndRemainIdempotent(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseAttributionEventRepository($connection);
        $result = new ConversionAttributionResult(
            conversionId: 'conversion_1',
            organizationId: 40,
            oauthClientId: 501,
            recordedByUserId: null,
            attributed: true,
            duplicate: false,
            clickEventId: 'click-1',
            decisionId: 'decision-1',
            campaignId: 100,
            windowSeconds: 86400,
            source: 'server_api',
            conversionName: 'purchase',
            valuePoints: 1200,
            occurredAt: new DateTimeImmutable('2026-06-08T12:00:00+00:00'),
        );

        $stored = $repository->recordConversion('conv-1', $result);
        $duplicateWrite = $repository->recordConversion('conv-1', new ConversionAttributionResult(
            conversionId: 'conversion_other',
            organizationId: 40,
            oauthClientId: 502,
            recordedByUserId: 7,
            attributed: false,
            duplicate: false,
            clickEventId: null,
            decisionId: null,
            campaignId: null,
            windowSeconds: 1,
            source: 'browser_pixel',
            conversionName: 'other',
            valuePoints: 0,
            occurredAt: new DateTimeImmutable('2026-06-09T12:00:00+00:00'),
        ));
        $loaded = (new DatabaseAttributionEventRepository($connection))->findConversion('conv-1');

        self::assertSame('conversion_1', $stored->conversionId);
        self::assertSame('conversion_1', $duplicateWrite->conversionId);
        self::assertNotNull($loaded);
        self::assertSame(40, $loaded->organizationId);
        self::assertSame(501, $loaded->oauthClientId);
        self::assertNull($loaded->recordedByUserId);
        self::assertTrue($loaded->attributed);
        self::assertFalse($loaded->duplicate);
        self::assertSame('click-1', $loaded->clickEventId);
        self::assertSame('decision-1', $loaded->decisionId);
        self::assertSame(100, $loaded->campaignId);
        self::assertSame(86400, $loaded->windowSeconds);
        self::assertSame('server_api', $loaded->source);
        self::assertSame('purchase', $loaded->conversionName);
        self::assertSame(1200, $loaded->valuePoints);
        self::assertSame('2026-06-08T12:00:00+00:00', $loaded->occurredAt->format(DATE_ATOM));
    }

    public function testAttributionServiceUsesPersistedServingClicksWithoutInMemorySeed(): void
    {
        $connection = $this->createConnection();
        $decision = $this->decision('decision-service', 300);
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        (new DatabaseAdEventRepository($connection))->recordClick(
            $decision,
            'click-service',
            new DateTimeImmutable('2026-06-08T10:00:00+00:00'),
        );
        $service = new AttributionService(new DatabaseAttributionEventRepository($connection), defaultWindowSeconds: 86400);

        $first = $service->recordServerApiConversion([
            'event_id' => 'conv-service',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
            'value_points' => 900,
            'occurred_at' => '2026-06-08T11:00:00+00:00',
        ], organizationId: 40, oauthClientId: 501, recordedByUserId: null);
        $second = (new AttributionService(new DatabaseAttributionEventRepository($connection), defaultWindowSeconds: 86400))
            ->recordServerApiConversion([
                'event_id' => 'conv-service',
                'viewer_id' => 'viewer-1',
                'conversion_name' => 'purchase',
                'occurred_at' => '2026-06-08T11:05:00+00:00',
            ], organizationId: 40, oauthClientId: 501, recordedByUserId: null);

        self::assertTrue($first->attributed);
        self::assertFalse($first->duplicate);
        self::assertSame(40, $first->organizationId);
        self::assertSame(501, $first->oauthClientId);
        self::assertSame('click-service', $first->clickEventId);
        self::assertSame('decision-service', $first->decisionId);
        self::assertSame(300, $first->campaignId);
        self::assertTrue($second->duplicate);
        self::assertSame($first->conversionId, $second->conversionId);
        self::assertSame(900, $second->valuePoints);
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DatabaseServingPersistenceRepositoryTest::createSchema($connection);

        return $connection;
    }

    private function decision(string $decisionId, int $campaignId, string $viewerId = 'viewer-1'): AdDecision
    {
        return new AdDecision(
            decisionId: $decisionId,
            siteId: 10,
            slotId: 20,
            viewerId: $viewerId,
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
