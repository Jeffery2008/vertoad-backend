<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver as DbalDriver;
use Doctrine\DBAL\Driver\Connection as DbalDriverConnection;
use Doctrine\DBAL\Driver\Middleware as DbalDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DbalDriverResult;
use Doctrine\DBAL\Driver\Statement as DbalDriverStatement;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Reporting\DatabaseConversionPathRepository;

final class ConversionPathRepositoryTest extends TestCase
{
    public function testFindsAttributedTouchpointsWithinWindowAndOrganizationScope(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-window', 'decision-1', 'viewer-1', '2026-06-08 10:00:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-window', 'decision-1', 'viewer-1', '2026-06-08 10:15:00', 40, 50);
        $this->insertEvent($connection, 'impression', 'imp-outside-window', 'decision-1', 'viewer-1', '2026-06-08 08:30:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-invalid', 'decision-1', 'viewer-1', '2026-06-08 10:30:00', 40, 50, valid: false);
        $this->insertEvent($connection, 'impression', 'imp-other-viewer', 'decision-2', 'viewer-2', '2026-06-08 10:05:00', 40, 50);
        $this->insertEvent($connection, 'impression', 'imp-other-org', 'decision-3', 'viewer-1', '2026-06-08 10:06:00', 41, 51);
        $this->insertConversion($connection, 'conversion:order-1', 'order-1', 'clk-window', '2026-06-08 11:00:00', 7200, 1_500);
        $this->insertConversion($connection, 'conversion:unattributed', 'order-unattributed', null, '2026-06-08 11:05:00', 7200, 9_999, attributed: false);

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'campaign_id' => 30,
            'site_id' => 10,
            'slot_id' => 20,
            'from' => new DateTimeImmutable('2026-06-08T10:45:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-08T11:30:00+00:00'),
            'max_touchpoints' => 10,
        ]);

        self::assertCount(1, $journeys);
        self::assertSame('conversion:order-1', $journeys[0]->conversionEventId);
        self::assertSame('order-1', $journeys[0]->conversionId);
        self::assertSame(1_500, $journeys[0]->valuePoints);
        self::assertSame('viewer-1', $journeys[0]->viewerId);
        self::assertCount(2, $journeys[0]->touchpoints);
        self::assertSame('impression', $journeys[0]->touchpoints[0]->eventType);
        self::assertSame('imp-window', $journeys[0]->touchpoints[0]->eventId);
        self::assertSame(1, $journeys[0]->touchpoints[0]->position);
        self::assertSame('click', $journeys[0]->touchpoints[1]->eventType);
        self::assertSame('clk-window', $journeys[0]->touchpoints[1]->eventId);
        self::assertSame(2, $journeys[0]->touchpoints[1]->position);
    }

    public function testReturnsEmptyListWhenNoConversionCohortMatches(): void
    {
        $connection = $this->createConnection();

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'from' => new DateTimeImmutable('2026-06-08T00:00:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        ]);

        self::assertSame([], $journeys);
    }

    public function testSkipsConversionWhenStoredWindowExcludesAttributedClick(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'click', 'clk-outside-stored-window', 'decision-1', 'viewer-1', '2026-06-08 10:15:00', 40, 50);
        $this->insertConversion($connection, 'conversion:outside-stored-window', 'outside-stored-window', 'clk-outside-stored-window', '2026-06-08 11:00:00', 60, 1_500);

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'max_touchpoints' => 10,
        ]);

        self::assertSame([], $journeys);
    }

    public function testMaxTouchpointsKeepsNearestTouchpointsBeforeConversion(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-early', 'decision-1', 'viewer-1', '2026-06-08 09:00:00', 40, 50);
        $this->insertEvent($connection, 'impression', 'imp-near', 'decision-1', 'viewer-1', '2026-06-08 10:00:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-nearest', 'decision-1', 'viewer-1', '2026-06-08 10:55:00', 40, 50);
        $this->insertConversion($connection, 'conversion:order-2', 'order-2', 'clk-nearest', '2026-06-08 11:00:00', 10800, 2_000);

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'max_touchpoints' => 2,
        ]);

        self::assertCount(1, $journeys);
        self::assertSame(['imp-near', 'clk-nearest'], array_map(
            static fn ($touchpoint): string => $touchpoint->eventId,
            $journeys[0]->touchpoints,
        ));
        self::assertSame([1, 2], array_map(
            static fn ($touchpoint): int => $touchpoint->position,
            $journeys[0]->touchpoints,
        ));
    }

    public function testEntityFiltersSelectConversionCohortWithoutTruncatingJourneyTouchpoints(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-other-campaign-site', 'decision-1', 'viewer-1', '2026-06-08 09:30:00', 40, 50, campaignId: 31, siteId: 11, slotId: 21);
        $this->insertEvent($connection, 'click', 'clk-filtered-cohort', 'decision-2', 'viewer-1', '2026-06-08 10:15:00', 40, 50, campaignId: 30, siteId: 10, slotId: 20);
        $this->insertEvent($connection, 'impression', 'imp-other-org-hidden', 'decision-3', 'viewer-1', '2026-06-08 10:20:00', 41, 51, campaignId: 32, siteId: 12, slotId: 22);
        $this->insertConversion($connection, 'conversion:filtered-cohort', 'filtered-cohort', 'clk-filtered-cohort', '2026-06-08 11:00:00', 7200, 1_500);

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'campaign_id' => 30,
            'site_id' => 10,
            'slot_id' => 20,
            'from' => new DateTimeImmutable('2026-06-08T10:45:00+00:00'),
            'to' => new DateTimeImmutable('2026-06-08T11:30:00+00:00'),
            'max_touchpoints' => 10,
        ]);

        self::assertCount(1, $journeys);
        self::assertSame(['imp-other-campaign-site', 'clk-filtered-cohort'], array_map(
            static fn ($touchpoint): string => $touchpoint->eventId,
            $journeys[0]->touchpoints,
        ));
        self::assertSame([31, 30], array_map(
            static fn ($touchpoint): ?int => $touchpoint->campaignId,
            $journeys[0]->touchpoints,
        ));
        self::assertSame([11, 10], array_map(
            static fn ($touchpoint): int => $touchpoint->siteId,
            $journeys[0]->touchpoints,
        ));
    }

    public function testPublisherAndUnscopedFiltersUseMatchingOrganizationColumns(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'click', 'clk-publisher', 'decision-1', 'viewer-1', '2026-06-08 10:15:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-other-publisher', 'decision-4', 'viewer-4', '2026-06-08 10:20:00', 41, 51);
        $this->insertConversion($connection, 'conversion:publisher', 'publisher-order', 'clk-publisher', '2026-06-08 11:00:00', 7200, 1_500);
        $this->insertConversion($connection, 'conversion:other-publisher', 'other-publisher-order', 'clk-other-publisher', '2026-06-08 11:05:00', 7200, 1_800);

        $repository = new DatabaseConversionPathRepository($connection);
        $publisherJourneys = $repository->findAttributedJourneys([
            'portal' => 'publisher',
            'organization_id' => 50,
            'max_touchpoints' => 10,
        ]);
        $unscopedJourneys = $repository->findAttributedJourneys([
            'max_touchpoints' => 10,
        ]);

        self::assertCount(1, $publisherJourneys);
        self::assertSame('publisher-order', $publisherJourneys[0]->conversionId);
        self::assertCount(2, $unscopedJourneys);
    }

    public function testFetchesTouchpointsForMultipleConversionsInOneBatchQuery(): void
    {
        $counter = new QueryCounter();
        $connection = $this->createConnection($counter);
        $this->insertEvent($connection, 'impression', 'imp-one', 'decision-1', 'viewer-1', '2026-06-08 10:00:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-one', 'decision-1', 'viewer-1', '2026-06-08 10:15:00', 40, 50);
        $this->insertEvent($connection, 'impression', 'imp-two', 'decision-2', 'viewer-2', '2026-06-08 10:10:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-two', 'decision-2', 'viewer-2', '2026-06-08 10:20:00', 40, 50);
        $this->insertConversion($connection, 'conversion:one', 'one', 'clk-one', '2026-06-08 11:00:00', 7200, 1_500);
        $this->insertConversion($connection, 'conversion:two', 'two', 'clk-two', '2026-06-08 11:05:00', 7200, 1_800);

        $counter->reset();
        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'max_touchpoints' => 10,
        ]);

        self::assertCount(2, $journeys);
        self::assertSame(2, $counter->selects);
    }

    public function testCropsBatchedTouchpointsAgainstEachConversionWindow(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'impression', 'imp-first-window', 'decision-1', 'viewer-1', '2026-06-08 09:30:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-first-window', 'decision-1', 'viewer-1', '2026-06-08 10:45:00', 40, 50);
        $this->insertEvent($connection, 'click', 'clk-second-window', 'decision-2', 'viewer-1', '2026-06-08 11:04:30', 40, 50);
        $this->insertConversion($connection, 'conversion:first-window', 'first-window', 'clk-first-window', '2026-06-08 11:00:00', 7200, 1_500);
        $this->insertConversion($connection, 'conversion:second-window', 'second-window', 'clk-second-window', '2026-06-08 11:05:00', 60, 2_000);

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'max_touchpoints' => 10,
        ]);
        $touchpointIdsByConversion = [];
        foreach ($journeys as $journey) {
            $touchpointIdsByConversion[$journey->conversionId] = array_map(
                static fn ($touchpoint): string => $touchpoint->eventId,
                $journey->touchpoints,
            );
        }

        self::assertSame(['imp-first-window', 'clk-first-window'], $touchpointIdsByConversion['first-window']);
        self::assertSame(['clk-second-window'], $touchpointIdsByConversion['second-window']);
    }

    public function testKeepsCohortConversionWhenTouchpointCampaignIsMissing(): void
    {
        $connection = $this->createConnection();
        $this->insertEvent($connection, 'click', 'clk-missing-campaign-touchpoint', 'decision-1', 'viewer-1', '2026-06-08 10:15:00', 40, 50);
        $connection->update('ad_serving_events', ['campaign_id' => null], [
            'event_type' => 'click',
            'event_id' => 'clk-missing-campaign-touchpoint',
        ]);
        $this->insertConversion(
            $connection,
            'conversion:missing-campaign-touchpoint',
            'missing-campaign-touchpoint',
            'clk-missing-campaign-touchpoint',
            '2026-06-08 11:00:00',
            7200,
            1_500,
        );

        $journeys = (new DatabaseConversionPathRepository($connection))->findAttributedJourneys([
            'portal' => 'advertiser',
            'organization_id' => 40,
            'campaign_id' => 30,
            'max_touchpoints' => 10,
        ]);

        self::assertCount(1, $journeys);
        self::assertSame('missing-campaign-touchpoint', $journeys[0]->conversionId);
        self::assertCount(1, $journeys[0]->touchpoints);
        self::assertSame('clk-missing-campaign-touchpoint', $journeys[0]->touchpoints[0]->eventId);
        self::assertNull($journeys[0]->touchpoints[0]->campaignId);
    }

    private function createConnection(?QueryCounter $counter = null): Connection
    {
        $configuration = new Configuration();
        if ($counter !== null) {
            $configuration->setMiddlewares([new QueryCountingMiddleware($counter)]);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $connection->executeStatement(
            'CREATE TABLE ad_serving_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(32) NOT NULL,
                event_id VARCHAR(160) NOT NULL,
                decision_id VARCHAR(160) NOT NULL,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                viewer_id VARCHAR(160) NOT NULL,
                ad_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                advertiser_organization_id INTEGER NULL,
                publisher_organization_id INTEGER NULL,
                cost_points INTEGER NULL,
                occurred_at DATETIME NOT NULL,
                valid INTEGER NOT NULL,
                reason VARCHAR(120) NULL,
                visible_ratio NUMERIC NULL,
                visible_ms INTEGER NULL,
                billing_status VARCHAR(32) NOT NULL DEFAULT "pending",
                billed_points INTEGER NOT NULL DEFAULT 0,
                publisher_earning_points INTEGER NOT NULL DEFAULT 0,
                billing_reason VARCHAR(120) NULL,
                billing_processed_at DATETIME NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE attribution_conversions (
                event_id VARCHAR(160) PRIMARY KEY,
                conversion_id VARCHAR(160) NOT NULL,
                organization_id INTEGER NULL,
                oauth_client_id INTEGER NULL,
                recorded_by_user_id INTEGER NULL,
                attributed INTEGER NOT NULL,
                click_event_id VARCHAR(160) NULL,
                decision_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                window_seconds INTEGER NOT NULL,
                source VARCHAR(64) NOT NULL,
                conversion_name VARCHAR(160) NOT NULL,
                value_points INTEGER NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );

        return $connection;
    }

    private function insertEvent(
        Connection $connection,
        string $eventType,
        string $eventId,
        string $decisionId,
        string $viewerId,
        string $occurredAt,
        int $advertiserOrganizationId,
        int $publisherOrganizationId,
        bool $valid = true,
        ?int $campaignId = null,
        ?int $siteId = null,
        ?int $slotId = null,
    ): void {
        $connection->insert('ad_serving_events', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'decision_id' => $decisionId,
            'site_id' => $siteId ?? ($advertiserOrganizationId === 40 ? 10 : 11),
            'slot_id' => $slotId ?? ($advertiserOrganizationId === 40 ? 20 : 21),
            'viewer_id' => $viewerId,
            'ad_id' => 'ad-1',
            'campaign_id' => $campaignId ?? ($advertiserOrganizationId === 40 ? 30 : 31),
            'advertiser_organization_id' => $advertiserOrganizationId,
            'publisher_organization_id' => $publisherOrganizationId,
            'cost_points' => $eventType === 'impression' ? 10 : 20,
            'occurred_at' => $occurredAt,
            'valid' => $valid ? 1 : 0,
            'reason' => $valid ? null : 'invalid_click',
            'visible_ratio' => null,
            'visible_ms' => null,
            'billing_status' => 'billed',
            'billed_points' => $eventType === 'impression' ? 10 : 20,
            'publisher_earning_points' => $eventType === 'impression' ? 6 : 12,
            'billing_reason' => null,
            'billing_processed_at' => $occurredAt,
            'processed_at' => $occurredAt,
        ]);
    }

    private function insertConversion(
        Connection $connection,
        string $eventId,
        string $conversionId,
        ?string $clickEventId,
        string $occurredAt,
        int $windowSeconds,
        int $valuePoints,
        bool $attributed = true,
    ): void {
        $connection->insert('attribution_conversions', [
            'event_id' => $eventId,
            'conversion_id' => $conversionId,
            'organization_id' => 40,
            'oauth_client_id' => 501,
            'recorded_by_user_id' => null,
            'attributed' => $attributed ? 1 : 0,
            'click_event_id' => $clickEventId,
            'decision_id' => 'decision-1',
            'campaign_id' => 30,
            'window_seconds' => $windowSeconds,
            'source' => 'server_api',
            'conversion_name' => 'purchase',
            'value_points' => $valuePoints,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }
}

final class QueryCounter
{
    public int $selects = 0;

    public function reset(): void
    {
        $this->selects = 0;
    }

    public function count(string $sql): void
    {
        if (preg_match('/\A\s*SELECT\b/i', $sql) === 1) {
            $this->selects++;
        }
    }
}

final readonly class QueryCountingMiddleware implements DbalDriverMiddleware
{
    public function __construct(private QueryCounter $counter)
    {
    }

    public function wrap(DbalDriver $driver): DbalDriver
    {
        return new QueryCountingDriver($driver, $this->counter);
    }
}

final class QueryCountingDriver extends AbstractDriverMiddleware
{
    public function __construct(DbalDriver $driver, private readonly QueryCounter $counter)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): DbalDriverConnection
    {
        return new QueryCountingConnection(parent::connect($params), $this->counter);
    }
}

final class QueryCountingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DbalDriverConnection $connection, private readonly QueryCounter $counter)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DbalDriverStatement
    {
        $this->counter->count($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): DbalDriverResult
    {
        $this->counter->count($sql);

        return parent::query($sql);
    }
}
