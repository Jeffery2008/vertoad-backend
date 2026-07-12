<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\CloudflareRealIp;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SensitiveParameter;
use Throwable;
use VertoAD\Tests\Acceptance\RedisBacklog\RedisBacklogAcceptanceHarness;

final readonly class AcceptanceHttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public float $elapsedMilliseconds,
        public string $primaryIp,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $payload = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}

final class CloudflareRealIpAcceptanceHarness
{
    private const TRUSTED_TUNNEL_SOURCE = '127.0.0.1';
    private const UNTRUSTED_DIRECT_SOURCE = '127.0.0.3';
    private const SPOOFED_CONNECTING_IP = '203.0.113.250';

    private ?RedisBacklogAcceptanceHarness $baseHarness = null;
    /** @var resource|null */
    private $phpServer = null;
    /** @var array<int, resource> */
    private array $phpServerPipes = [];
    /** @var resource|null */
    private $quickTunnel = null;
    /** @var array<int, resource> */
    private array $quickTunnelPipes = [];
    /** @var array<string, array{process:string|false,env_exists:bool,env:mixed,server_exists:bool,server:mixed}> */
    private array $environmentBackup = [];
    /** @var list<string> */
    private array $temporaryProcessLogPaths = [];
    /** @var array<string, int>|null */
    private ?array $cleanupEvidence = null;
    private bool $cleaned = false;
    private string $quickTunnelLog = '';
    private string $phpServerLog = '';
    private string $tunnelBaseUrl = '';
    private int $port;

    private function __construct(
        private readonly string $rootPath,
        private readonly string $runId,
        private readonly string $cloudflaredBinary,
        private readonly string $safeViewerId,
        private readonly string $riskViewerId,
        private readonly string $untrustedViewerId,
        private readonly string $serveRequestId,
        private readonly string $trackRequestId,
        private readonly string $clickRequestId,
        private readonly string $riskRequestId,
        private readonly string $untrustedRequestId,
        private readonly string $impressionEventId,
        private readonly string $clickEventId,
    ) {
        $this->port = self::reserveLoopbackPort();
    }

    public static function boot(string $rootPath): self
    {
        $runId = bin2hex(random_bytes(8));
        $harness = new self(
            rootPath: $rootPath,
            runId: $runId,
            cloudflaredBinary: self::cloudflaredBinary(),
            safeViewerId: 'cf-safe-' . $runId,
            riskViewerId: 'cf-risk-' . $runId,
            untrustedViewerId: 'cf-untrusted-' . $runId,
            serveRequestId: 'req-cf-serve-' . $runId,
            trackRequestId: 'req-cf-track-' . $runId,
            clickRequestId: 'req-cf-click-' . $runId,
            riskRequestId: 'req-cf-risk-' . $runId,
            untrustedRequestId: 'req-cf-untrusted-' . $runId,
            impressionEventId: 'cf-impression-' . $runId,
            clickEventId: 'cf-click-' . $runId,
        );

        try {
            $harness->initialize();

            return $harness;
        } catch (Throwable $exception) {
            try {
                $harness->cleanup();
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function mysqlVersion(): string
    {
        return $this->base()->mysqlVersion();
    }

    public function databaseName(): string
    {
        return $this->base()->databaseName();
    }

    public function redisPrefix(): string
    {
        return $this->base()->redisPrefix();
    }

    public function migrationCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM phinxlog');
    }

    public function cloudflaredVersion(): string
    {
        $result = $this->runCommand([$this->cloudflaredBinary, 'version'], 10);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException('Unable to read the cloudflared version.');
        }

        return trim($result['stdout']);
    }

    /** @return array<string, mixed> */
    public function runScenario(): array
    {
        $official = $this->officialCloudflareEvidence();
        $transport = $this->cloudflareTransportEvidence();

        $before = $this->databaseCounts();
        $serve = $this->serveThroughCloudflare($this->safeViewerId, $this->serveRequestId);
        $servePayload = $serve->json();
        $decisionId = trim((string) ($servePayload['data']['decision_id'] ?? ''));
        if ($decisionId === '') {
            throw new \RuntimeException('Cloudflare serve response did not contain a decision id.');
        }

        $track = $this->requestThroughCloudflare(
            'POST',
            '/api/v1/ads/track',
            [
                'decision_id' => $decisionId,
                'viewer_id' => $this->safeViewerId,
                'event_id' => $this->impressionEventId,
                'visible_ratio' => 0.75,
                'visible_ms' => 1_500,
            ],
            $this->trackRequestId,
        );
        $click = $this->requestThroughCloudflare(
            'GET',
            '/api/v1/ads/click?' . http_build_query([
                'decision_id' => $decisionId,
                'viewer_id' => $this->safeViewerId,
                'event_id' => $this->clickEventId,
            ], encoding_type: PHP_QUERY_RFC3986),
            null,
            $this->clickRequestId,
        );
        $risk = $this->serveThroughCloudflare($this->riskViewerId, $this->riskRequestId);
        $untrusted = $this->serveDirectFromUntrustedSource();

        $cronRuns = $this->drainServingEvents();
        $after = $this->databaseCounts();

        return [
            'official' => $official,
            'transport' => $transport,
            'responses' => [
                'serve' => $this->responseEvidence($serve),
                'track' => $this->responseEvidence($track),
                'click' => $this->responseEvidence($click),
                'risk' => $this->responseEvidence($risk),
                'untrusted' => $this->responseEvidence($untrusted),
            ],
            'ids' => [
                'decision_id' => $decisionId,
                'serve_request_id' => $this->serveRequestId,
                'track_request_id' => $this->trackRequestId,
                'click_request_id' => $this->clickRequestId,
                'risk_request_id' => $this->riskRequestId,
                'untrusted_request_id' => $this->untrustedRequestId,
                'impression_event_id' => $this->impressionEventId,
                'click_event_id' => $this->clickEventId,
            ],
            'before' => $before,
            'after' => $after,
            'cron_runs' => $cronRuns,
            'queue_links' => $this->queueLinks(),
            'queue_tasks' => $this->queueTasks(),
            'decisions' => $this->decisionEvidence(),
            'events' => $this->eventEvidence(),
            'risk_logs' => $this->riskLogEvidence(),
        ];
    }

    /**
     * @return array{
     *   redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,
     *   database_before:int,database_after:int,php_server_stopped:int,quick_tunnel_stopped:int,
     *   process_logs_after:int
     * }
     */
    public function cleanup(): array
    {
        if ($this->cleaned) {
            return $this->cleanupEvidence ?? $this->emptyCleanupEvidence();
        }
        $this->cleaned = true;

        $failure = null;
        $quickTunnelStopped = 1;
        $phpServerStopped = 1;

        try {
            $this->stopProcess($this->quickTunnel, $this->quickTunnelPipes);
            $quickTunnelStopped = $this->isProcessRunning($this->quickTunnel) ? 0 : 1;
        } catch (Throwable $exception) {
            $failure ??= $exception;
            $quickTunnelStopped = 0;
        }

        try {
            $this->stopProcess($this->phpServer, $this->phpServerPipes);
            $phpServerStopped = $this->isProcessRunning($this->phpServer) ? 0 : 1;
        } catch (Throwable $exception) {
            $failure ??= $exception;
            $phpServerStopped = 0;
        }

        $processLogsAfter = 0;
        try {
            $processLogsAfter = $this->cleanupTemporaryProcessLogs();
        } catch (Throwable $exception) {
            $failure ??= $exception;
            $processLogsAfter = count(array_filter($this->temporaryProcessLogPaths, 'is_file'));
        }

        $this->restoreEnvironment();

        $baseEvidence = [
            'redis_keys_before' => 0,
            'redis_keys_deleted' => 0,
            'redis_keys_after' => 0,
            'database_before' => 0,
            'database_after' => 0,
        ];
        try {
            if ($this->baseHarness !== null) {
                $baseEvidence = $this->baseHarness->cleanup();
                $this->baseHarness = null;
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        $this->cleanupEvidence = [
            ...$baseEvidence,
            'php_server_stopped' => $phpServerStopped,
            'quick_tunnel_stopped' => $quickTunnelStopped,
            'process_logs_after' => $processLogsAfter,
        ];

        if ($failure !== null) {
            throw new \RuntimeException('Cloudflare real IP acceptance cleanup failed.', previous: $failure);
        }
        if (
            $this->cleanupEvidence['redis_keys_after'] !== 0
            || $this->cleanupEvidence['database_after'] !== 0
            || $phpServerStopped !== 1
            || $quickTunnelStopped !== 1
            || $processLogsAfter !== 0
        ) {
            throw new \RuntimeException('Cloudflare real IP acceptance left isolated resources behind.');
        }

        return $this->cleanupEvidence;
    }

    private function initialize(): void
    {
        $this->progress('initialize:start');
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The Cloudflare real IP acceptance runner requires ext-curl.');
        }

        $this->progress('base-harness:start');
        $this->baseHarness = RedisBacklogAcceptanceHarness::boot($this->rootPath);
        $this->progress('base-harness:ready');
        $this->seedGeoPolicyAndRiskFeature();
        $this->progress('database-fixtures:ready');
        $this->applyEnvironment();
        $this->progress('environment:ready');
        $this->startPhpServer();
        $this->progress('php-server:ready');
        $this->startQuickTunnel();
        $this->progress('quick-tunnel:ready');
    }

    private function seedGeoPolicyAndRiskFeature(): void
    {
        $connection = $this->connection();
        $userId = (int) $connection->fetchOne('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        if ($userId < 1) {
            throw new \RuntimeException('The Cloudflare acceptance fixture has no configuration author.');
        }

        $connection->insert('system_config_versions', [
            'version_id' => 'cfgv_cf_real_ip_' . $this->runId,
            'config_key' => 'serving.geo_provider',
            'version' => 1,
            'value_json' => json_encode([
                'enabled' => true,
                'include_builtins' => false,
                'providers' => [],
                'batch_size' => 10,
                'max_attempts' => 3,
                'retry_backoff_seconds' => 60,
                'cache_ttl_seconds' => 3600,
                'queue_source' => 'serving',
                'default_country_code' => null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_by_user_id' => $userId,
        ]);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $connection->insert('fraud_risk_features', [
            'scope_type' => 'viewer',
            'scope_id' => $this->riskViewerId,
            'window_start' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
            'window_end' => $now->modify('+1 hour')->format('Y-m-d H:i:s'),
            'site_id' => $this->base()->siteId(),
            'slot_id' => $this->base()->slotId(),
            'viewer_id' => $this->riskViewerId,
            'impressions' => 100,
            'clicks' => 95,
            'invalid_clicks' => 90,
            'ctr_per_mille' => 950,
            'invalid_click_rate_per_mille' => 947,
            'risk_score' => 100,
            'risk_bucket' => 'high',
            'reasons_json' => json_encode(['cloudflare_acceptance_high_risk'], JSON_THROW_ON_ERROR),
            'computed_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function applyEnvironment(): void
    {
        foreach ([
            'APP_DEBUG' => 'false',
            'IP_GEO_REPOSITORY' => 'database',
            'CLOUDFLARE_REAL_IP_HEADER' => 'CF-Connecting-IP',
            'CLOUDFLARE_TRUSTED_PROXIES' => self::TRUSTED_TUNNEL_SOURCE,
        ] as $name => $value) {
            $this->environmentBackup[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
            if (!putenv($name . '=' . $value)) {
                throw new \RuntimeException('Unable to configure the Cloudflare acceptance environment.');
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function restoreEnvironment(): void
    {
        foreach (array_reverse($this->environmentBackup, true) as $name => $previous) {
            if ($previous['process'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous['process']);
            }
            if ($previous['env_exists']) {
                $_ENV[$name] = $previous['env'];
            } else {
                unset($_ENV[$name]);
            }
            if ($previous['server_exists']) {
                $_SERVER[$name] = $previous['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }
        $this->environmentBackup = [];
    }

    private function startPhpServer(): void
    {
        $this->progress('php-server:start');
        $router = __DIR__ . DIRECTORY_SEPARATOR . 'cloudflare-real-ip-router.php';
        [$this->phpServer, $this->phpServerPipes] = $this->startProcess([
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            '-t',
            $this->rootPath . DIRECTORY_SEPARATOR . 'public',
            $router,
        ]);
        $this->progress('php-server:process-started');

        $deadline = microtime(true) + 15;
        do {
            $this->phpServerLog .= $this->readProcessOutput($this->phpServerPipes);
            if (!$this->isProcessRunning($this->phpServer)) {
                throw new \RuntimeException('The Cloudflare acceptance PHP server exited early: ' . $this->diagnostic($this->phpServerLog));
            }
            try {
                $this->progress('php-server:probe-start');
                $response = $this->httpRequest('GET', $this->originBaseUrl() . '/__cloudflare-real-ip/transport');
                $this->progress('php-server:probe-status-' . $response->status);
                if ($response->status === 200) {
                    return;
                }
            } catch (Throwable $exception) {
                $this->progress('php-server:probe-error-' . $exception::class);
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The Cloudflare acceptance PHP server did not become ready.');
    }

    private function startQuickTunnel(): void
    {
        $this->progress('quick-tunnel:start');
        [$this->quickTunnel, $this->quickTunnelPipes] = $this->startProcess([
            $this->cloudflaredBinary,
            'tunnel',
            '--no-autoupdate',
            '--protocol',
            'http2',
            '--output',
            'json',
            '--url',
            $this->originBaseUrl(),
        ]);
        $this->progress('quick-tunnel:process-started');

        $deadline = microtime(true) + 60;
        do {
            $this->quickTunnelLog .= $this->readProcessOutput($this->quickTunnelPipes);
            if (preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~i', $this->quickTunnelLog, $matches) === 1) {
                $this->tunnelBaseUrl = strtolower($matches[0]);
                $this->progress('quick-tunnel:url-published');
                $this->waitForQuickTunnel();

                return;
            }
            if (!$this->isProcessRunning($this->quickTunnel)) {
                throw new \RuntimeException('cloudflared exited before publishing a Quick Tunnel URL: ' . $this->diagnostic($this->quickTunnelLog));
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('cloudflared did not publish a Quick Tunnel URL: ' . $this->diagnostic($this->quickTunnelLog));
    }

    private function waitForQuickTunnel(): void
    {
        $this->progress('quick-tunnel:reachability-start');
        $deadline = microtime(true) + 120;
        $registered = false;
        $nextProbeAt = 0.0;
        do {
            $this->quickTunnelLog .= $this->readProcessOutput($this->quickTunnelPipes);
            if (!$registered && str_contains($this->quickTunnelLog, 'Registered tunnel connection')) {
                $registered = true;
                $nextProbeAt = microtime(true);
                $this->progress('quick-tunnel:registered');
            }
            if ($registered && microtime(true) >= $nextProbeAt) {
                try {
                    $response = $this->httpRequest(
                        'GET',
                        $this->tunnelBaseUrl . '/__cloudflare-real-ip/transport',
                        null,
                        ['Cache-Control' => 'no-store'],
                    );
                    if ($response->status === 200 && $response->header('cf-ray') !== '') {
                        $this->progress('quick-tunnel:reachable');
                        return;
                    }
                } catch (Throwable $exception) {
                    $this->progress('quick-tunnel:probe-error-' . $exception::class);
                }
                $nextProbeAt = microtime(true) + 2.0;
            }
            if (!$this->isProcessRunning($this->quickTunnel)) {
                throw new \RuntimeException('cloudflared exited while the Quick Tunnel was becoming ready.');
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The Cloudflare Quick Tunnel did not become reachable.');
    }

    /** @return array<string, mixed> */
    private function officialCloudflareEvidence(): array
    {
        $trace = $this->httpRequest('GET', 'https://www.cloudflare.com/cdn-cgi/trace');
        $ipv4 = $this->httpRequest('GET', 'https://www.cloudflare.com/ips-v4');
        $ipv6 = $this->httpRequest('GET', 'https://www.cloudflare.com/ips-v6');

        return [
            'trace_status' => $trace->status,
            'trace' => $this->parseKeyValueLines($trace->body),
            'trace_cf_ray' => $trace->header('cf-ray'),
            'trace_server' => $trace->header('server'),
            'ipv4_status' => $ipv4->status,
            'ipv4_ranges' => $this->parseCidrs($ipv4->body, 4),
            'ipv4_sha256' => hash('sha256', trim($ipv4->body)),
            'ipv6_status' => $ipv6->status,
            'ipv6_ranges' => $this->parseCidrs($ipv6->body, 6),
            'ipv6_sha256' => hash('sha256', trim($ipv6->body)),
        ];
    }

    /** @return array<string, mixed> */
    private function cloudflareTransportEvidence(): array
    {
        $response = $this->httpRequest(
            'GET',
            $this->tunnelBaseUrl . '/__cloudflare-real-ip/transport',
            null,
            ['Cache-Control' => 'no-store'],
        );
        $spoofAttempt = $this->httpRequest(
            'GET',
            $this->tunnelBaseUrl . '/__cloudflare-real-ip/transport',
            null,
            [
                'CF-Connecting-IP' => self::SPOOFED_CONNECTING_IP,
                'X-Forwarded-For' => self::SPOOFED_CONNECTING_IP,
                'Cache-Control' => 'no-store',
            ],
        );
        $spoofBody = null;
        try {
            $spoofBody = $spoofAttempt->json();
        } catch (Throwable) {
        }
        $spoofDisposition = $spoofAttempt->status === 200
            && is_array($spoofBody)
            && filter_var($spoofBody['cf_connecting_ip'] ?? null, FILTER_VALIDATE_IP) !== false
            && ($spoofBody['cf_connecting_ip'] ?? null) !== self::SPOOFED_CONNECTING_IP
                ? 'overwritten'
                : 'rejected_by_edge';

        return [
            'status' => $response->status,
            'headers' => [
                'cf_ray' => $response->header('cf-ray'),
                'server' => $response->header('server'),
            ],
            'body' => $response->json(),
            'spoofed_connecting_ip' => self::SPOOFED_CONNECTING_IP,
            'elapsed_ms' => $response->elapsedMilliseconds,
            'spoof_attempt' => [
                'status' => $spoofAttempt->status,
                'headers' => [
                    'cf_ray' => $spoofAttempt->header('cf-ray'),
                    'server' => $spoofAttempt->header('server'),
                    'content_type' => $spoofAttempt->header('content-type'),
                ],
                'body' => $spoofBody,
                'body_sha256' => hash('sha256', $spoofAttempt->body),
                'disposition' => $spoofDisposition,
            ],
        ];
    }

    private function serveThroughCloudflare(string $viewerId, string $requestId): AcceptanceHttpResponse
    {
        return $this->requestThroughCloudflare('POST', '/api/v1/ads/serve', [
            'site_id' => $this->base()->siteId(),
            'slot_id' => $this->base()->slotId(),
            'viewer_id' => $viewerId,
            'size' => ['width' => 300, 'height' => 250],
            'debug' => false,
        ], $requestId);
    }

    /** @param array<string, mixed>|null $body */
    private function requestThroughCloudflare(string $method, string $path, ?array $body, string $requestId): AcceptanceHttpResponse
    {
        return $this->httpRequest(
            $method,
            $this->tunnelBaseUrl . $path,
            $body,
            [
                'X-Request-Id' => $requestId,
                'User-Agent' => 'VertoAD Cloudflare Quick Tunnel acceptance',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function serveDirectFromUntrustedSource(): AcceptanceHttpResponse
    {
        return $this->httpRequest(
            'POST',
            $this->originBaseUrl() . '/api/v1/ads/serve',
            [
                'site_id' => $this->base()->siteId(),
                'slot_id' => $this->base()->slotId(),
                'viewer_id' => $this->untrustedViewerId,
                'size' => ['width' => 300, 'height' => 250],
                'debug' => false,
            ],
            [
                'X-Request-Id' => $this->untrustedRequestId,
                'CF-Connecting-IP' => self::SPOOFED_CONNECTING_IP,
                'User-Agent' => 'VertoAD untrusted direct acceptance',
            ],
            self::UNTRUSTED_DIRECT_SOURCE,
        );
    }

    /** @return list<array<string, mixed>> */
    private function drainServingEvents(): array
    {
        $runs = [];
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            if ($this->base()->pendingEventCount() === 0) {
                break;
            }
            $response = $this->httpRequest(
                'GET',
                $this->originBaseUrl() . '/api/v1/cron/jobs/redis-events-consume/run',
                null,
                [
                    'X-Cron-Token' => $this->base()->cronToken(),
                    'X-Request-Id' => 'req-cf-cron-' . $this->runId . '-' . $attempt,
                ],
            );
            $payload = $response->json();
            if ($response->status !== 200) {
                throw new \RuntimeException('The serving event drain Cron failed: ' . $this->diagnostic($response->body));
            }
            $runs[] = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        }
        if ($this->base()->pendingEventCount() !== 0) {
            throw new \RuntimeException('The serving event drain left buffered events behind.');
        }

        return $runs;
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        $connection = $this->connection();

        return [
            'geo_records' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_records'),
            'geo_tasks' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_tasks'),
            'geo_request_links' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_request_ids'),
            'decisions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_decisions'),
            'events' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_events'),
            'risk_logs' => (int) $connection->fetchOne('SELECT COUNT(*) FROM operation_risk_decision_logs'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function queueLinks(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT rid.request_id, t.ip_address, t.status, t.source, t.provider_id, t.region_hint '
            . 'FROM ip_geo_lookup_request_ids rid '
            . 'INNER JOIN ip_geo_lookup_tasks t ON t.ip_hash = rid.ip_hash '
            . 'WHERE rid.request_id IN (?) ORDER BY rid.request_id',
            [[
                $this->serveRequestId,
                $this->trackRequestId,
                $this->clickRequestId,
                $this->riskRequestId,
                $this->untrustedRequestId,
            ]],
            [ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => [
            'request_id' => (string) $row['request_id'],
            'ip_address' => (string) $row['ip_address'],
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'provider_id' => $row['provider_id'] === null ? null : (string) $row['provider_id'],
            'region_hint' => $row['region_hint'] === null ? null : (string) $row['region_hint'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function queueTasks(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT ip_address, status, source, attempts, provider_id, resolved_at, request_id, request_ids_json '
            . 'FROM ip_geo_lookup_tasks ORDER BY ip_address',
        );
    }

    /** @return list<array<string, mixed>> */
    private function decisionEvidence(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT request_id, viewer_id, filled, reason, ip_address, geo_code, decision_id '
            . 'FROM ad_serving_decisions WHERE request_id IN (?) ORDER BY request_id',
            [[
                $this->serveRequestId,
                $this->riskRequestId,
                $this->untrustedRequestId,
            ]],
            [ArrayParameterType::STRING],
        );
    }

    /** @return list<array<string, mixed>> */
    private function eventEvidence(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT event_type, event_id, request_id, viewer_id, valid, reason, ip_address, geo_code '
            . 'FROM ad_serving_events WHERE request_id IN (?) ORDER BY request_id, event_type',
            [[
                $this->serveRequestId,
                $this->trackRequestId,
                $this->clickRequestId,
                $this->riskRequestId,
                $this->untrustedRequestId,
            ]],
            [ArrayParameterType::STRING],
        );
    }

    /** @return list<array<string, mixed>> */
    private function riskLogEvidence(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT request_id, action, reason_codes_json, ip_address, endpoint, http_method, viewer_id, ad_decision_id '
            . 'FROM operation_risk_decision_logs WHERE request_id = ? ORDER BY occurred_at, decision_id',
            [$this->riskRequestId],
        );
    }

    /** @return array<string, mixed> */
    private function responseEvidence(AcceptanceHttpResponse $response): array
    {
        $json = [];
        if ($response->body !== '') {
            try {
                $json = $response->json();
            } catch (Throwable) {
            }
        }

        return [
            'status' => $response->status,
            'request_id' => $response->header('x-request-id'),
            'location' => $response->header('location'),
            'elapsed_ms' => $response->elapsedMilliseconds,
            'primary_ip' => $response->primaryIp,
            'json' => $json,
        ];
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, string> $headers
     */
    private function httpRequest(
        string $method,
        string $url,
        ?array $jsonBody = null,
        array $headers = [],
        ?string $sourceIp = null,
    ): AcceptanceHttpResponse {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize the acceptance HTTP client.');
        }

        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $body = null;
        if ($jsonBody !== null) {
            $body = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headerLines[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return $length;
                }
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];

                    return $length;
                }
                $separator = strpos($trimmed, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($trimmed, $separator + 1));
                }

                return $length;
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        if (str_starts_with($url, $this->originBaseUrl())) {
            curl_setopt($curl, CURLOPT_PROXY, '');
            curl_setopt($curl, CURLOPT_NOPROXY, '*');
        }
        if ($sourceIp !== null) {
            curl_setopt($curl, CURLOPT_INTERFACE, $sourceIp);
            curl_setopt($curl, CURLOPT_PROXY, '');
            curl_setopt($curl, CURLOPT_NOPROXY, '*');
        }

        $started = hrtime(true);
        $responseBody = curl_exec($curl);
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
        if ($responseBody === false) {
            $message = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Acceptance HTTP request failed: ' . $message);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);
        curl_close($curl);

        return new AcceptanceHttpResponse(
            status: $status,
            headers: $responseHeaders,
            body: (string) $responseBody,
            elapsedMilliseconds: $elapsedMilliseconds,
            primaryIp: $primaryIp,
        );
    }

    /** @return array<string, string> */
    private function parseKeyValueLines(string $body): array
    {
        $values = [];
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $key = trim(substr($line, 0, $separator));
            if ($key !== '') {
                $values[$key] = trim(substr($line, $separator + 1));
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function parseCidrs(string $body, int $version): array
    {
        $ranges = [];
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            $cidr = trim($line);
            if ($cidr === '' || !str_contains($cidr, '/')) {
                continue;
            }
            [$network, $prefix] = explode('/', $cidr, 2);
            $packed = @inet_pton($network);
            $expectedBytes = $version === 4 ? 4 : 16;
            $maxPrefix = $version === 4 ? 32 : 128;
            if (
                $packed === false
                || strlen($packed) !== $expectedBytes
                || !ctype_digit($prefix)
                || (int) $prefix > $maxPrefix
            ) {
                throw new \RuntimeException('Cloudflare published an invalid IP range.');
            }
            $ranges[] = $cidr;
        }

        return $ranges;
    }

    /** @return array{0:resource,1:array<int, resource>} */
    private function startProcess(array $command): array
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        $stdoutPath = null;
        $stderrPath = null;
        if ($windows) {
            $logDirectory = dirname($this->rootPath) . DIRECTORY_SEPARATOR . 'tmp'
                . DIRECTORY_SEPARATOR . 'cloudflare-real-ip-process';
            if (!is_dir($logDirectory) && !mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
                throw new \RuntimeException('Unable to create the Cloudflare acceptance process log directory.');
            }
            $logId = $this->runId . '-' . bin2hex(random_bytes(6));
            $stdoutPath = $logDirectory . DIRECTORY_SEPARATOR . $logId . '.stdout.log';
            $stderrPath = $logDirectory . DIRECTORY_SEPARATOR . $logId . '.stderr.log';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'wb'],
                2 => ['file', $stderrPath, 'wb'],
            ];
        } else {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }
        $process = proc_open($command, $descriptors, $pipes, $this->rootPath, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the acceptance child process.');
        }
        fclose($pipes[0]);
        unset($pipes[0]);
        if ($windows) {
            foreach ([$stdoutPath, $stderrPath] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $this->temporaryProcessLogPaths[] = $path;
            }
            $stdout = is_string($stdoutPath) ? fopen($stdoutPath, 'rb') : false;
            $stderr = is_string($stderrPath) ? fopen($stderrPath, 'rb') : false;
            if ($stdout === false || $stderr === false) {
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                proc_terminate($process);
                throw new \RuntimeException('Unable to open the Cloudflare acceptance process logs.');
            }
            $pipes = [1 => $stdout, 2 => $stderr];
        } else {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
        }

        return [$process, $pipes];
    }

    /** @param array<int, resource> $pipes */
    private function readProcessOutput(array $pipes): string
    {
        $output = '';
        foreach ($pipes as $pipe) {
            $metadata = stream_get_meta_data($pipe);
            if (($metadata['seekable'] ?? false) === true) {
                $position = ftell($pipe);
                if (is_int($position)) {
                    $uri = $metadata['uri'] ?? null;
                    if (is_string($uri) && $uri !== '') {
                        clearstatcache(true, $uri);
                    }
                    fseek($pipe, $position);
                }
            }
            $chunk = stream_get_contents($pipe);
            if (is_string($chunk) && $chunk !== '') {
                $output .= $chunk;
            }
        }

        return $output;
    }

    /** @param resource|null $process @param array<int, resource> $pipes */
    private function stopProcess(&$process, array &$pipes): void
    {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                if (DIRECTORY_SEPARATOR === '\\' && isset($status['pid'])) {
                    exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F >NUL 2>&1');
                } else {
                    proc_terminate($process);
                }
                $deadline = microtime(true) + 5;
                do {
                    usleep(100_000);
                    $status = proc_get_status($process);
                } while (($status['running'] ?? false) && microtime(true) < $deadline);
                if ($status['running'] ?? false) {
                    proc_terminate($process, 9);
                }
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $pipes = [];
        if (is_resource($process)) {
            proc_close($process);
        }
        $process = null;
    }

    /** @param resource|null $process */
    private function isProcessRunning($process): bool
    {
        return is_resource($process) && (proc_get_status($process)['running'] ?? false);
    }

    /** @return array{stdout:string,stderr:string,exit_code:int} */
    private function runCommand(array $command, int $timeoutSeconds): array
    {
        [$process, $pipes] = $this->startProcess($command);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        if ($status['running'] ?? false) {
            proc_terminate($process, 9);
            throw new \RuntimeException('Acceptance command timed out.');
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exitCode = (int) ($status['exitcode'] ?? -1);
        proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }

    private function connection(): Connection
    {
        return $this->base()->connection();
    }

    private function base(): RedisBacklogAcceptanceHarness
    {
        return $this->baseHarness ?? throw new \LogicException('The Cloudflare acceptance base harness is unavailable.');
    }

    private function originBaseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    private function diagnostic(string $value): string
    {
        $value = $this->baseHarness?->redactDiagnostic($value) ?? $value;

        return substr(trim($value), -4_000);
    }

    private function cleanupTemporaryProcessLogs(): int
    {
        $directories = [];
        foreach ($this->temporaryProcessLogPaths as $path) {
            $directories[dirname($path)] = true;
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Unable to remove a Cloudflare acceptance process log.');
            }
        }
        $remaining = count(array_filter($this->temporaryProcessLogPaths, 'is_file'));
        $this->temporaryProcessLogPaths = [];
        foreach (array_keys($directories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        return $remaining;
    }

    private function progress(string $stage): void
    {
        $path = getenv('VERTOAD_CLOUDFLARE_REAL_IP_ACCEPTANCE_PROGRESS_FILE');
        if (!is_string($path) || trim($path) === '') {
            return;
        }

        @file_put_contents(
            trim($path),
            gmdate('c') . ' ' . $stage . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /** @return array<string, int> */
    private function emptyCleanupEvidence(): array
    {
        return [
            'redis_keys_before' => 0,
            'redis_keys_deleted' => 0,
            'redis_keys_after' => 0,
            'database_before' => 0,
            'database_after' => 0,
            'php_server_stopped' => 1,
            'quick_tunnel_stopped' => 1,
            'process_logs_after' => 0,
        ];
    }

    private static function cloudflaredBinary(): string
    {
        foreach ([
            getenv('VERTOAD_CLOUDFLARE_REAL_IP_ACCEPTANCE_CLOUDFLARED_BINARY'),
            getenv('CLOUDFLARED_BINARY'),
            DIRECTORY_SEPARATOR === '\\' ? 'E:\\Tools\\Cloudflared\\cloudflared.exe' : null,
            'cloudflared',
        ] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $candidate = trim($candidate);
            if (!str_contains($candidate, '/') && !str_contains($candidate, '\\')) {
                return $candidate;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('A cloudflared binary is required for Cloudflare real IP acceptance.');
    }

    private static function reserveLoopbackPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new \RuntimeException('Unable to reserve a loopback port: ' . $errorMessage, $errorCode);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($name) || preg_match('/:(\d+)$/D', $name, $matches) !== 1) {
            throw new \RuntimeException('Unable to determine the reserved loopback port.');
        }

        return (int) $matches[1];
    }
}
