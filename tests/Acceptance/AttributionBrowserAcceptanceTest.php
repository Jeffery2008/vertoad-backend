<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Tests\Acceptance\AttributionBrowser\AttributionBrowserAcceptanceHarness;

#[Group('external-tools-integration')]
#[Group('attribution-browser-acceptance')]
final class AttributionBrowserAcceptanceTest extends TestCase
{
    private ?AttributionBrowserAcceptanceHarness $harness = null;
    private bool $scenarioCompleted = false;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_ATTRIBUTION_BROWSER_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_ATTRIBUTION_BROWSER_ACCEPTANCE=1 with real MySQL, Redis, Node, and Chromium settings.',
            );
        }

        $this->harness = AttributionBrowserAcceptanceHarness::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->harness === null) {
            return;
        }

        $harness = $this->harness;
        $this->harness = null;
        $evidence = $harness->cleanup();
        self::assertSame($evidence['redis_keys_before'], $evidence['redis_keys_deleted']);
        self::assertSame(0, $evidence['redis_keys_after']);
        self::assertSame(1, $evidence['database_before']);
        self::assertSame(0, $evidence['database_after']);
        self::assertSame(1, $evidence['workspace_before']);
        self::assertSame(0, $evidence['workspace_after']);
        if ($this->scenarioCompleted) {
            self::assertGreaterThan(0, $evidence['redis_keys_before']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBrowserPixelUsesLastClickAndPublishesTheConversionInTheRoiReport(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^vertoad_attr_browser_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertMatchesRegularExpression(
            '/^vertoad:acceptance:attribution-browser:[a-f0-9]{16}:$/',
            $harness->redisPrefix(),
        );
        self::assertGreaterThanOrEqual(27, $harness->migrationCount());
        self::assertSame(1, (int) $harness->connection()->fetchOne(
            'SELECT COUNT(*) FROM ad_serving_events WHERE event_id = ?',
            [$harness->priorClickEventId()],
        ));
        $authentication = $harness->reportAuthenticationEvidence();
        self::assertTrue($authentication['stored'] ?? false, json_encode($authentication));
        self::assertTrue($authentication['hash_matches'] ?? false, json_encode($authentication));
        self::assertFalse($authentication['revoked'] ?? true, json_encode($authentication));
        self::assertTrue($authentication['authenticated'] ?? false, json_encode($authentication));
        self::assertSame($harness->advertiserOrganizationId(), $authentication['organization_id'] ?? null);
        self::assertSame(['report.read.own'], $authentication['scopes'] ?? null);

        $evidence = $harness->runBrowserScenario();
        self::assertSame(1, $evidence['requestCounts']['track'] ?? null);
        self::assertSame(1, $evidence['requestCounts']['click'] ?? null);
        self::assertSame(1, $evidence['requestCounts']['pixel'] ?? null);
        self::assertSame(1, $evidence['requestCounts']['consumeCron'] ?? null);
        self::assertSame(1, $evidence['requestCounts']['aggregateCron'] ?? null);
        self::assertSame(1, $evidence['requestCounts']['report'] ?? null);

        self::assertSame(302, $evidence['click']['status'] ?? null);
        self::assertSame($harness->clickEventId(), $evidence['click']['eventId'] ?? null);
        self::assertStringEndsWith('/checkout', (string) ($evidence['click']['landingUrl'] ?? ''));
        self::assertSame(201, $evidence['pixel']['status'] ?? null);
        self::assertSame('browser_pixel', $evidence['pixel']['source'] ?? null);
        self::assertSame('last_click', $evidence['pixel']['model'] ?? null);
        self::assertTrue($evidence['pixel']['attributed'] ?? false);
        self::assertSame($harness->clickEventId(), $evidence['pixel']['clickEventId'] ?? null);
        self::assertNotSame($harness->priorClickEventId(), $evidence['pixel']['clickEventId'] ?? null);
        self::assertSame(604800, $evidence['pixel']['windowSeconds'] ?? null);

        self::assertSame(200, $evidence['report']['totals']['conversion_value_points'] ?? null);
        self::assertSame(1, $evidence['report']['totals']['conversions'] ?? null);
        self::assertSame(40, $evidence['report']['totals']['spend_points'] ?? null);
        self::assertEqualsWithDelta(5.0, (float) ($evidence['report']['totals']['roi'] ?? 0), 0.0001);

        self::assertFileExists((string) ($evidence['screenshotPath'] ?? ''));
        $connection = $harness->connection();
        $events = $connection->fetchAllAssociative(
            'SELECT event_type, event_id, valid, billing_status, billed_points, publisher_earning_points, processed_at '
            . 'FROM ad_serving_events WHERE decision_id = ? ORDER BY occurred_at, id',
            [$harness->decisionId()],
        );
        self::assertCount(3, $events);
        self::assertSame($harness->priorClickEventId(), $events[0]['event_id']);
        self::assertSame('pending', $events[0]['billing_status']);
        self::assertSame('impression', $events[1]['event_type']);
        self::assertSame('skipped', $events[1]['billing_status']);
        self::assertSame('click', $events[2]['event_type']);
        self::assertSame($harness->clickEventId(), $events[2]['event_id']);
        self::assertSame('billed', $events[2]['billing_status']);
        self::assertSame(40, (int) $events[2]['billed_points']);
        self::assertSame(20, (int) $events[2]['publisher_earning_points']);
        self::assertNotNull($events[2]['processed_at']);

        $conversion = $connection->fetchAssociative(
            'SELECT event_id, organization_id, attributed, click_event_id, decision_id, campaign_id, '
            . 'window_seconds, source, conversion_name, value_points FROM attribution_conversions WHERE event_id = ?',
            [$harness->storedConversionEventId()],
        );
        self::assertIsArray($conversion);
        self::assertSame($harness->storedConversionEventId(), $conversion['event_id']);
        self::assertNull($conversion['organization_id']);
        self::assertSame(1, (int) $conversion['attributed']);
        self::assertSame($harness->clickEventId(), $conversion['click_event_id']);
        self::assertSame($harness->decisionId(), $conversion['decision_id']);
        self::assertSame($harness->campaignId(), (int) $conversion['campaign_id']);
        self::assertSame(604800, (int) $conversion['window_seconds']);
        self::assertSame('browser_pixel', $conversion['source']);
        self::assertSame('purchase', $conversion['conversion_name']);
        self::assertSame(200, (int) $conversion['value_points']);

        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM raw_events'));
        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_aggregates'));
        self::assertSame(960, (int) $connection->fetchOne(
            'SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = ?',
            [$harness->advertiserOrganizationId(), 'advertiser_balance'],
        ));
        self::assertSame(20, (int) $connection->fetchOne(
            'SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = ?',
            [$harness->publisherOrganizationId(), 'publisher_earnings'],
        ));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM spend_reservations WHERE status = ?', ['committed']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_earning_events'));

        $this->scenarioCompleted = true;
    }

    private function harness(): AttributionBrowserAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The attribution browser acceptance harness is unavailable.');
    }
}
