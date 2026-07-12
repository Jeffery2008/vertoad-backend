<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Tests\Acceptance\CloudflareRealIp\CloudflareRealIpAcceptanceHarness;

#[Group('redis-integration')]
#[Group('external-tools-integration')]
#[Group('cloudflare-real-ip-acceptance')]
final class CloudflareRealIpAcceptanceTest extends TestCase
{
    private ?CloudflareRealIpAcceptanceHarness $harness = null;
    private bool $scenarioCompleted = false;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_CLOUDFLARE_REAL_IP_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_CLOUDFLARE_REAL_IP_ACCEPTANCE=1 with real Cloudflare network, cloudflared, MySQL 8, and Redis settings.',
            );
        }

        $this->harness = CloudflareRealIpAcceptanceHarness::boot(dirname(__DIR__, 2));
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
        self::assertSame(1, $evidence['php_server_stopped']);
        self::assertSame(1, $evidence['quick_tunnel_stopped']);
        self::assertSame(0, $evidence['process_logs_after']);
        if ($this->scenarioCompleted) {
            self::assertGreaterThan(0, $evidence['redis_keys_before']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloudflareOverwritesSpoofedRealIpAndServingQueuesGeoWithoutBlocking(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^vertoad_acceptance_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertMatchesRegularExpression('/^vertoad:acceptance:[a-f0-9]{16}:$/', $harness->redisPrefix());
        self::assertGreaterThanOrEqual(27, $harness->migrationCount());
        self::assertMatchesRegularExpression('/^cloudflared version \d{4}\.\d+\.\d+/', $harness->cloudflaredVersion());

        $evidence = $harness->runScenario();
        $official = $evidence['official'];
        self::assertSame(200, $official['trace_status'] ?? null);
        self::assertSame(200, $official['ipv4_status'] ?? null);
        self::assertSame(200, $official['ipv6_status'] ?? null);
        self::assertTrue(filter_var($official['trace']['ip'] ?? null, FILTER_VALIDATE_IP) !== false);
        self::assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) ($official['trace']['colo'] ?? ''));
        self::assertNotSame('', (string) ($official['trace']['tls'] ?? ''));
        self::assertGreaterThanOrEqual(10, count($official['ipv4_ranges'] ?? []));
        self::assertGreaterThanOrEqual(5, count($official['ipv6_ranges'] ?? []));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($official['ipv4_sha256'] ?? ''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($official['ipv6_sha256'] ?? ''));

        $transport = $evidence['transport'];
        self::assertSame(200, $transport['status'] ?? null);
        self::assertSame('127.0.0.1', $transport['body']['remote_addr'] ?? null);
        self::assertTrue(filter_var($transport['body']['cf_connecting_ip'] ?? null, FILTER_VALIDATE_IP) !== false);
        self::assertNotSame($transport['spoofed_connecting_ip'] ?? null, $transport['body']['cf_connecting_ip'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]+-[A-Z]{3}$/i', (string) ($transport['body']['cf_ray'] ?? ''));
        self::assertStringEndsWith('.trycloudflare.com', (string) ($transport['body']['host'] ?? ''));
        self::assertSame('cloudflare', strtolower((string) ($transport['headers']['server'] ?? '')));
        self::assertContains($transport['spoof_attempt']['disposition'] ?? null, ['overwritten', 'rejected_by_edge']);
        if (($transport['spoof_attempt']['disposition'] ?? null) === 'overwritten') {
            self::assertSame(200, $transport['spoof_attempt']['status'] ?? null);
            self::assertNotSame(
                $transport['spoofed_connecting_ip'] ?? null,
                $transport['spoof_attempt']['body']['cf_connecting_ip'] ?? null,
            );
        } else {
            self::assertGreaterThanOrEqual(400, $transport['spoof_attempt']['status'] ?? 0);
            self::assertSame(
                'cloudflare',
                strtolower((string) ($transport['spoof_attempt']['headers']['server'] ?? '')),
            );
        }

        $responses = $evidence['responses'];
        self::assertSame(200, $responses['serve']['status'] ?? null);
        self::assertTrue($responses['serve']['json']['data']['filled'] ?? false);
        self::assertSame($evidence['ids']['serve_request_id'], $responses['serve']['request_id'] ?? null);
        self::assertSame(200, $responses['track']['status'] ?? null);
        self::assertTrue($responses['track']['json']['data']['accepted'] ?? false);
        self::assertSame($evidence['ids']['track_request_id'], $responses['track']['request_id'] ?? null);
        self::assertSame(302, $responses['click']['status'] ?? null);
        self::assertSame('https://advertiser.example/acceptance', $responses['click']['location'] ?? null);
        self::assertSame($evidence['ids']['click_request_id'], $responses['click']['request_id'] ?? null);
        self::assertSame(200, $responses['risk']['status'] ?? null);
        self::assertFalse($responses['risk']['json']['data']['filled'] ?? true);
        self::assertSame('fraud_high_risk_viewer', $responses['risk']['json']['data']['reason'] ?? null);
        self::assertSame($evidence['ids']['risk_request_id'], $responses['risk']['request_id'] ?? null);
        self::assertSame(200, $responses['untrusted']['status'] ?? null);
        self::assertTrue($responses['untrusted']['json']['data']['filled'] ?? false);
        self::assertSame($evidence['ids']['untrusted_request_id'], $responses['untrusted']['request_id'] ?? null);

        self::assertSame([
            'geo_records' => 0,
            'geo_tasks' => 0,
            'geo_request_links' => 0,
            'decisions' => 0,
            'events' => 0,
            'risk_logs' => 0,
        ], $evidence['before']);
        self::assertSame(0, $evidence['after']['geo_records'] ?? null);
        self::assertGreaterThanOrEqual(2, $evidence['after']['geo_tasks'] ?? 0);
        self::assertSame(5, $evidence['after']['geo_request_links'] ?? null);
        self::assertSame(3, $evidence['after']['decisions'] ?? null);
        self::assertSame(5, $evidence['after']['events'] ?? null);
        self::assertSame(1, $evidence['after']['risk_logs'] ?? null);
        self::assertNotEmpty($evidence['cron_runs']);

        $links = $this->indexBy($evidence['queue_links'], 'request_id');
        self::assertCount(5, $links);
        foreach ([
            $evidence['ids']['serve_request_id'],
            $evidence['ids']['track_request_id'],
            $evidence['ids']['click_request_id'],
            $evidence['ids']['risk_request_id'],
        ] as $requestId) {
            self::assertArrayHasKey($requestId, $links);
            self::assertTrue(filter_var($links[$requestId]['ip_address'], FILTER_VALIDATE_IP) !== false);
            self::assertNotSame($transport['spoofed_connecting_ip'], $links[$requestId]['ip_address']);
            self::assertNotSame('127.0.0.1', $links[$requestId]['ip_address']);
            self::assertSame($transport['body']['cf_connecting_ip'], $links[$requestId]['ip_address']);
            self::assertSame('pending', $links[$requestId]['status']);
            self::assertSame('serving', $links[$requestId]['source']);
            self::assertNull($links[$requestId]['provider_id']);
        }
        $untrustedRequestId = $evidence['ids']['untrusted_request_id'];
        self::assertSame('127.0.0.3', $links[$untrustedRequestId]['ip_address'] ?? null);
        self::assertNotSame($transport['spoofed_connecting_ip'], $links[$untrustedRequestId]['ip_address'] ?? null);
        self::assertSame('pending', $links[$untrustedRequestId]['status'] ?? null);

        foreach ($evidence['queue_tasks'] as $task) {
            self::assertSame('pending', $task['status']);
            self::assertSame('serving', $task['source']);
            self::assertSame(0, (int) $task['attempts']);
            self::assertNull($task['provider_id']);
            self::assertNull($task['resolved_at']);
        }

        $decisions = $this->indexBy($evidence['decisions'], 'request_id');
        self::assertCount(3, $decisions);
        self::assertSame(1, (int) $decisions[$evidence['ids']['serve_request_id']]['filled']);
        self::assertNull($decisions[$evidence['ids']['serve_request_id']]['geo_code']);
        self::assertSame($links[$evidence['ids']['serve_request_id']]['ip_address'], $decisions[$evidence['ids']['serve_request_id']]['ip_address']);
        self::assertSame(0, (int) $decisions[$evidence['ids']['risk_request_id']]['filled']);
        self::assertSame('fraud_high_risk_viewer', $decisions[$evidence['ids']['risk_request_id']]['reason']);
        self::assertNull($decisions[$evidence['ids']['risk_request_id']]['geo_code']);
        self::assertSame('127.0.0.3', $decisions[$untrustedRequestId]['ip_address']);
        self::assertNull($decisions[$untrustedRequestId]['geo_code']);

        $events = $this->indexBy($evidence['events'], 'request_id');
        self::assertCount(5, $events);
        self::assertSame('serve', $events[$evidence['ids']['serve_request_id']]['event_type']);
        self::assertSame('impression', $events[$evidence['ids']['track_request_id']]['event_type']);
        self::assertSame('click', $events[$evidence['ids']['click_request_id']]['event_type']);
        self::assertSame('serve', $events[$evidence['ids']['risk_request_id']]['event_type']);
        self::assertSame('serve', $events[$untrustedRequestId]['event_type']);
        foreach ($events as $requestId => $event) {
            self::assertSame($links[$requestId]['ip_address'], $event['ip_address']);
            self::assertNull($event['geo_code']);
        }

        self::assertCount(1, $evidence['risk_logs']);
        $riskLog = $evidence['risk_logs'][0];
        self::assertSame($evidence['ids']['risk_request_id'], $riskLog['request_id']);
        self::assertSame('ads.serve.risk_rejected', $riskLog['action']);
        self::assertSame('/api/v1/ads/serve', $riskLog['endpoint']);
        self::assertSame('POST', $riskLog['http_method']);
        self::assertSame($links[$evidence['ids']['risk_request_id']]['ip_address'], $riskLog['ip_address']);
        self::assertContains('fraud_high_risk_viewer', json_decode((string) $riskLog['reason_codes_json'], true, flags: JSON_THROW_ON_ERROR));

        $this->scenarioCompleted = true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '');
            if ($key !== '') {
                $indexed[$key] = $row;
            }
        }

        return $indexed;
    }

    private function harness(): CloudflareRealIpAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The Cloudflare real IP acceptance harness is unavailable.');
    }
}
