<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Service\Reporting\ReportQueryService;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Tests\Acceptance\RedisBacklog\RedisBacklogAcceptanceHarness;

#[Group('redis-integration')]
#[Group('redis-mysql-acceptance')]
final class RedisBacklogDrainAcceptanceTest extends TestCase
{
    private ?RedisBacklogAcceptanceHarness $harness = null;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_REDIS_MYSQL_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_REDIS_MYSQL_ACCEPTANCE=1 with real Redis and MySQL 8 environment settings.',
            );
        }

        $this->harness = RedisBacklogAcceptanceHarness::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->harness === null) {
            return;
        }

        $evidence = $this->harness->cleanup();
        self::assertGreaterThan(0, $evidence['redis_keys_before']);
        self::assertSame($evidence['redis_keys_before'], $evidence['redis_keys_deleted']);
        self::assertSame(0, $evidence['redis_keys_after']);
        self::assertSame(1, $evidence['database_before']);
        self::assertSame(0, $evidence['database_after']);
        $this->harness = null;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProductionAppDrainsRedisBacklogThroughProtectedCronApiAndAggregatesIdempotently(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^vertoad_acceptance_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertMatchesRegularExpression('/^vertoad:acceptance:[a-f0-9]{16}:$/', $harness->redisPrefix());
        self::assertSame(0, $harness->pendingEventCount());

        $events = [];
        for ($index = 1; $index <= 3; ++$index) {
            $viewerId = 'acceptance-viewer-' . $index;
            $serveResponse = $this->request('POST', '/api/v1/ads/serve', [
                'site_id' => $harness->siteId(),
                'slot_id' => $harness->slotId(),
                'viewer_id' => $viewerId,
                'size' => ['width' => 300, 'height' => 250],
                'debug' => false,
            ], ['X-Request-Id' => 'req-acceptance-serve-' . $index]);
            $serve = $this->json($serveResponse);
            self::assertSame(200, $serveResponse->getStatusCode());
            self::assertTrue($serve['data']['filled'] ?? false);
            self::assertSame($harness->campaignId(), $serve['data']['ad']['campaign_id'] ?? null);
            $decisionId = (string) ($serve['data']['decision_id'] ?? '');
            self::assertNotSame('', $decisionId);

            $impressionId = 'acceptance-impression-' . $index;
            $trackBody = [
                'decision_id' => $decisionId,
                'viewer_id' => $viewerId,
                'event_id' => $impressionId,
                'visible_ratio' => 0.75,
                'visible_ms' => 1_500,
            ];
            $trackResponse = $this->request(
                'POST',
                '/api/v1/ads/track',
                $trackBody,
                ['X-Request-Id' => 'req-acceptance-track-' . $index],
            );
            $track = $this->json($trackResponse);
            self::assertSame(200, $trackResponse->getStatusCode());
            self::assertTrue($track['data']['accepted'] ?? false);
            self::assertFalse($track['data']['duplicate'] ?? true);

            $trackReplay = $this->request(
                'POST',
                '/api/v1/ads/track',
                $trackBody,
                ['X-Request-Id' => 'req-acceptance-track-replay-' . $index],
            );
            self::assertSame(200, $trackReplay->getStatusCode());
            self::assertTrue($this->json($trackReplay)['data']['duplicate'] ?? false);

            $clickId = 'acceptance-click-' . $index;
            $clickUri = '/api/v1/ads/click?' . http_build_query([
                'decision_id' => $decisionId,
                'viewer_id' => $viewerId,
                'event_id' => $clickId,
            ], encoding_type: PHP_QUERY_RFC3986);
            $clickResponse = $this->request(
                'GET',
                $clickUri,
                null,
                ['X-Request-Id' => 'req-acceptance-click-' . $index],
            );
            $clickStatus = $clickResponse->getStatusCode();
            self::assertSame(
                302,
                $clickStatus,
                $clickStatus === 302
                    ? ''
                    : $this->responseDiagnostic($clickResponse, 'req-acceptance-click-' . $index),
            );
            self::assertSame('https://advertiser.example/acceptance', $clickResponse->getHeaderLine('Location'));

            $clickReplay = $this->request(
                'GET',
                $clickUri,
                null,
                ['X-Request-Id' => 'req-acceptance-click-replay-' . $index],
            );
            $clickReplayStatus = $clickReplay->getStatusCode();
            self::assertSame(
                302,
                $clickReplayStatus,
                $clickReplayStatus === 302
                    ? ''
                    : $this->responseDiagnostic($clickReplay, 'req-acceptance-click-replay-' . $index),
            );
            self::assertSame('https://advertiser.example/acceptance', $clickReplay->getHeaderLine('Location'));

            $events[] = [
                'serve' => $decisionId,
                'impression' => $impressionId,
                'click' => $clickId,
            ];
            self::assertSame($index * 3, $harness->pendingEventCount());
        }

        self::assertSame(9, $harness->pendingEventCount());
        $unauthorized = $this->request('GET', '/api/v1/cron/jobs/redis-events-consume/run');
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame('unauthorized', $this->json($unauthorized)['error']['code'] ?? null);
        self::assertSame(9, $harness->pendingEventCount());

        $first = $this->runCron('redis-events-consume');
        self::assertSame(4, $first['metrics']['consumed'] ?? null);
        $failedDiagnostics = $harness->connection()->fetchAllAssociative(
            "SELECT event_type, billing_status, billing_reason FROM ad_serving_events WHERE billing_status = 'failed' "
            . 'ORDER BY event_type, event_id',
        );
        $persistenceDiagnostic = null;
        if (($first['metrics']['failed'] ?? 0) !== 0) {
            $container = $harness->app()->getContainer();
            self::assertNotNull($container);
            $buffered = $container->get(AdEventRepositoryInterface::class)->searchEvents(['limit' => 1]);
            try {
                $persistenceDiagnostic = 'persist_result=' . json_encode(
                    $container->get(DatabaseAdEventRepository::class)->persist($buffered[0]),
                    JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable $exception) {
                $persistenceDiagnostic = $harness->redactDiagnostic(
                    $exception::class . ': ' . $exception->getMessage(),
                );
            }
        }
        self::assertSame(0, $first['metrics']['failed'] ?? null, json_encode(
            [
                'run' => $first,
                'failed_events' => $failedDiagnostics,
                'persistence_diagnostic' => $persistenceDiagnostic,
            ],
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(5, $harness->pendingEventCount());
        $second = $this->runCron('redis-events-consume');
        self::assertSame(4, $second['metrics']['consumed'] ?? null);
        self::assertSame(1, $harness->pendingEventCount());
        $third = $this->runCron('redis-events-consume');
        self::assertSame(1, $third['metrics']['consumed'] ?? null);
        self::assertSame(0, $harness->pendingEventCount());
        $empty = $this->runCron('redis-events-consume');
        self::assertSame(0, $empty['metrics']['consumed'] ?? null);

        $consumeMetrics = $this->sumMetrics([$first, $second, $third]);
        self::assertSame(9, $consumeMetrics['consumed']);
        self::assertSame(3, $consumeMetrics['billed']);
        self::assertSame(6, $consumeMetrics['skipped']);
        self::assertSame(0, $consumeMetrics['duplicates']);
        self::assertSame(0, $consumeMetrics['failed']);

        $connection = $harness->connection();
        $this->assertPersistedEvents($connection);

        foreach ($events[0] as $eventType => $eventId) {
            $harness->requeue($eventType, $eventId);
        }
        self::assertSame(3, $harness->pendingEventCount());
        $duplicate = $this->runCron('redis-events-consume');
        self::assertSame(3, $duplicate['metrics']['consumed'] ?? null);
        self::assertSame(3, $duplicate['metrics']['duplicates'] ?? null);
        self::assertSame(0, $duplicate['metrics']['billed'] ?? null);
        self::assertSame(0, $duplicate['metrics']['skipped'] ?? null);
        self::assertSame(0, $duplicate['metrics']['failed'] ?? null);
        self::assertSame(0, $harness->pendingEventCount());
        $this->assertPersistedEvents($connection);

        $firstAggregate = $this->runCron('aggregate-statistics');
        self::assertSame(3, $firstAggregate['metrics']['day_rows'] ?? null);
        self::assertSame(3, $firstAggregate['metrics']['hour_rows'] ?? null);
        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_aggregates'));
        $this->assertReportDashboards();

        $aggregateSnapshot = $connection->fetchAllAssociative(
            'SELECT granularity, bucket_start, dimension_key, organization_role, organization_id, campaign_id, '
            . 'site_id, slot_id, impressions, clicks, spend_points, revenue_points '
            . 'FROM report_aggregates ORDER BY granularity, organization_role, organization_id',
        );
        $secondAggregate = $this->runCron('aggregate-statistics');
        self::assertSame(3, $secondAggregate['metrics']['day_rows'] ?? null);
        self::assertSame(3, $secondAggregate['metrics']['hour_rows'] ?? null);
        self::assertSame($aggregateSnapshot, $connection->fetchAllAssociative(
            'SELECT granularity, bucket_start, dimension_key, organization_role, organization_id, campaign_id, '
            . 'site_id, slot_id, impressions, clicks, spend_points, revenue_points '
            . 'FROM report_aggregates ORDER BY granularity, organization_role, organization_id',
        ));
        $this->assertPersistedEvents($connection);
        self::assertGreaterThan(0, $harness->redisKeyCount());
    }

    private function assertPersistedEvents(Connection $connection): void
    {
        self::assertSame(9, (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_events'));
        self::assertSame(9, (int) $connection->fetchOne('SELECT COUNT(*) FROM raw_events'));
        self::assertSame(9, (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_event_dedup'));
        self::assertSame(9, (int) $connection->fetchOne('SELECT COUNT(*) FROM raw_event_dedup'));
        self::assertSame(9, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ad_serving_events WHERE processed_at IS NOT NULL',
        ));
        self::assertSame(9, (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT request_id) FROM ad_serving_events WHERE request_id IS NOT NULL',
        ));
        self::assertSame([
            ['event_type' => 'click', 'event_count' => 3],
            ['event_type' => 'impression', 'event_count' => 3],
            ['event_type' => 'serve', 'event_count' => 3],
        ], array_map(
            static fn (array $row): array => [
                'event_type' => (string) $row['event_type'],
                'event_count' => (int) $row['event_count'],
            ],
            $connection->fetchAllAssociative(
                'SELECT event_type, COUNT(*) AS event_count FROM ad_serving_events GROUP BY event_type ORDER BY event_type',
            ),
        ));
        self::assertSame(3, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'click' AND billing_status = 'billed' "
            . 'AND billed_points = 10 AND publisher_earning_points = 5',
        ));
        self::assertSame(3, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'impression' AND billing_status = 'skipped' "
            . "AND billing_reason = 'zero_cost'",
        ));
        self::assertSame(3, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ad_serving_events WHERE event_type = 'serve' AND billing_status = 'skipped' "
            . "AND billing_reason = 'non_billable_event'",
        ));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_earning_events'));
        self::assertSame(970, (int) $connection->fetchOne(
            "SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = 'advertiser_balance'",
            [$this->harness()->advertiserOrganizationId()],
        ));
        self::assertSame(15, (int) $connection->fetchOne(
            "SELECT balance_points FROM ledger_account_balances WHERE organization_id = ? AND account_type = 'publisher_earnings'",
            [$this->harness()->publisherOrganizationId()],
        ));
    }

    private function assertReportDashboards(): void
    {
        $container = $this->harness()->app()->getContainer();
        self::assertNotNull($container);
        $reports = $container->get(ReportQueryService::class);

        $advertiser = $reports->dashboard([
            'portal' => 'advertiser',
            'organization_id' => $this->harness()->advertiserOrganizationId(),
            'granularity' => 'hour',
        ]);
        self::assertSame(3, $advertiser['totals']['impressions'] ?? null);
        self::assertSame(3, $advertiser['totals']['clicks'] ?? null);
        self::assertSame(100.0, $advertiser['totals']['ctr'] ?? null);
        self::assertSame(30, $advertiser['totals']['spend_points'] ?? null);
        self::assertSame(0, $advertiser['totals']['revenue_points'] ?? null);

        $publisher = $reports->dashboard([
            'portal' => 'publisher',
            'organization_id' => $this->harness()->publisherOrganizationId(),
            'granularity' => 'hour',
        ]);
        self::assertSame(3, $publisher['totals']['impressions'] ?? null);
        self::assertSame(3, $publisher['totals']['clicks'] ?? null);
        self::assertSame(100.0, $publisher['totals']['ctr'] ?? null);
        self::assertSame(0, $publisher['totals']['spend_points'] ?? null);
        self::assertSame(15, $publisher['totals']['revenue_points'] ?? null);

        $platform = $reports->dashboard(['portal' => 'admin', 'granularity' => 'hour']);
        self::assertSame(3, $platform['totals']['impressions'] ?? null);
        self::assertSame(3, $platform['totals']['clicks'] ?? null);
        self::assertSame(100.0, $platform['totals']['ctr'] ?? null);
        self::assertSame(30, $platform['totals']['spend_points'] ?? null);
        self::assertSame(15, $platform['totals']['revenue_points'] ?? null);
    }

    /** @return array<string, mixed> */
    private function runCron(string $job): array
    {
        $response = $this->request(
            'GET',
            '/api/v1/cron/jobs/' . rawurlencode($job) . '/run',
            null,
            ['X-Cron-Token' => $this->harness()->cronToken()],
        );
        $payload = $this->json($response);
        $diagnostic = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            $errorId = (string) ($payload['error']['operation_error_id'] ?? '');
            $error = $errorId === '' ? false : $this->harness()->connection()->fetchAssociative(
                'SELECT source, message FROM operation_error_logs WHERE error_id = ?',
                [$errorId],
            );
            if (is_array($error)) {
                $diagnostic = $this->harness()->redactDiagnostic(
                    (string) $error['source'] . ': ' . (string) $error['message'],
                );
            }
        }
        self::assertSame(200, $response->getStatusCode(), $diagnostic);
        self::assertSame($job, $payload['data']['job'] ?? null);
        self::assertSame('completed', $payload['data']['status'] ?? null);
        self::assertTrue($payload['data']['acquired_lock'] ?? false);

        return $payload['data'];
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array{consumed:int,billed:int,skipped:int,duplicates:int,failed:int}
     */
    private function sumMetrics(array $runs): array
    {
        $totals = ['consumed' => 0, 'billed' => 0, 'skipped' => 0, 'duplicates' => 0, 'failed' => 0];
        foreach ($runs as $run) {
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += (int) ($run['metrics'][$metric] ?? 0);
            }
        }

        return $totals;
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $headers */
    private function request(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('User-Agent', 'VertoAD Redis acceptance runner');
        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->harness()->app()->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }

    private function harness(): RedisBacklogAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The Redis/MySQL acceptance harness is unavailable.');
    }

    private function responseDiagnostic(ResponseInterface $response, string $requestId): string
    {
        $payload = $this->json($response);
        $errors = $this->harness()->connection()->fetchAllAssociative(
            'SELECT source, message, redacted_context_json FROM operation_error_logs WHERE request_id = ? ORDER BY occurred_at DESC',
            [$requestId],
        );

        return $this->harness()->redactDiagnostic((string) json_encode([
            'status' => $response->getStatusCode(),
            'body' => $payload,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
