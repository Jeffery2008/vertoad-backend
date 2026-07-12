<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Tests\Acceptance\RedisBacklog\RedisBacklogAcceptanceHarness;

final class ServingLoadScenario
{
    private const LANDING_URL = 'https://advertiser.example/acceptance';

    public function __construct(
        private readonly string $rootPath,
        private readonly ServingLoadOptions $options,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $harness = null;
        $pool = null;
        $stageResults = [];
        $evidence = [];
        $cleanup = null;
        $violations = [];
        $fatalError = null;
        $workerLogs = [];

        try {
            $harness = RedisBacklogAcceptanceHarness::boot($this->rootPath);
            $this->primeCanonicalGeo($harness);
            $environment = $this->workerEnvironment();
            $pool = PhpHttpServerPool::start($this->rootPath, $this->options->workers, $environment);
            $client = new ConcurrentHttpLoadClient($this->options->timeoutMs);
            $runToken = substr($harness->databaseName(), strlen('vertoad_acceptance_'));

            if ($this->options->warmup > 0) {
                $warmupThreshold = new ServingLoadThreshold(PHP_FLOAT_MAX, 1.0, 0.000001);
                $warmup = $this->flow(
                    $client,
                    $pool->baseUrls(),
                    $harness,
                    $runToken . '-warmup',
                    $this->options->warmup,
                    min($this->options->concurrency, $this->options->warmup),
                    [
                        'serve' => $warmupThreshold,
                        'track' => $warmupThreshold,
                        'click' => $warmupThreshold,
                    ],
                );
                foreach ($warmup as $stage => $result) {
                    if ($result->metrics->errors > 0) {
                        throw new \RuntimeException('Warmup ' . $stage . ' requests did not all satisfy the endpoint contract.');
                    }
                }
            }

            $measured = $this->flow(
                $client,
                $pool->baseUrls(),
                $harness,
                $runToken . '-measured',
                $this->options->requests,
                $this->options->concurrency,
                $this->options->thresholds(),
            );
            foreach ($measured as $stage => $result) {
                $stageResults[$stage] = $result->metrics->toArray();
                foreach ($result->metrics->violations as $violation) {
                    $violations[] = $stage . ': ' . $violation;
                }
            }

            if (array_keys($measured) !== ['serve', 'track', 'click']) {
                $violations[] = 'The measured flow stopped before all three endpoint stages completed.';
            } else {
                $expectedFlows = $this->options->warmup + $this->options->requests;
                $expectedEvents = $expectedFlows * 3;
                $connection = $harness->connection();
                $evidence = [
                    'php_version' => PHP_VERSION,
                    'mysql_version' => $harness->mysqlVersion(),
                    'database_name_shape_valid' => preg_match('/^vertoad_acceptance_[a-f0-9]{16}$/D', $harness->databaseName()) === 1,
                    'redis_prefix_shape_valid' => preg_match('/^vertoad:acceptance:[a-f0-9]{16}:$/D', $harness->redisPrefix()) === 1,
                    'canonical_geo_cache_hit' => (int) $connection->fetchOne(
                        'SELECT COUNT(*) FROM ip_geo_records WHERE ip_address = ?',
                        ['127.0.0.1'],
                    ) === 1,
                    'expected_complete_flows' => $expectedFlows,
                    'decision_rows' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_decisions'),
                    'filled_decision_rows' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_decisions WHERE filled = 1'),
                    'distinct_viewers' => (int) $connection->fetchOne('SELECT COUNT(DISTINCT viewer_id) FROM ad_serving_decisions'),
                    'distinct_decision_request_ids' => (int) $connection->fetchOne(
                        'SELECT COUNT(DISTINCT request_id) FROM ad_serving_decisions WHERE request_id IS NOT NULL',
                    ),
                    'expected_buffered_events' => $expectedEvents,
                    'buffered_events' => $harness->pendingEventCount(),
                    'redirects_followed' => false,
                    'verified_redirect_location' => self::LANDING_URL,
                ];
                foreach ([
                    'decision_rows',
                    'filled_decision_rows',
                    'distinct_viewers',
                    'distinct_decision_request_ids',
                ] as $field) {
                    if ($evidence[$field] !== $expectedFlows) {
                        $violations[] = $field . ' expected ' . $expectedFlows . ', got ' . $evidence[$field];
                    }
                }
                if ($evidence['buffered_events'] !== $expectedEvents) {
                    $violations[] = 'buffered_events expected ' . $expectedEvents . ', got ' . $evidence['buffered_events'];
                }
            }
        } catch (\Throwable $exception) {
            $fatalError = $harness instanceof RedisBacklogAcceptanceHarness
                ? $harness->redactDiagnostic($exception::class . ': ' . $exception->getMessage())
                : $exception::class . ': ' . $exception->getMessage();
            if ($pool instanceof PhpHttpServerPool) {
                $workerLogs = array_map(
                    static fn (string $log): string => $harness instanceof RedisBacklogAcceptanceHarness
                        ? $harness->redactDiagnostic($log)
                        : $log,
                    $pool->logTails(),
                );
            }
        } finally {
            if ($pool instanceof PhpHttpServerPool) {
                try {
                    $pool->stop();
                } catch (\Throwable $exception) {
                    $fatalError ??= 'Worker cleanup failed: ' . $exception->getMessage();
                }
            }
            if ($harness instanceof RedisBacklogAcceptanceHarness) {
                try {
                    $cleanup = $harness->cleanup();
                } catch (\Throwable $exception) {
                    $fatalError ??= $harness->redactDiagnostic('Fixture cleanup failed: ' . $exception->getMessage());
                }
            }
        }

        if (is_array($cleanup)) {
            if ($cleanup['redis_keys_before'] < 1) {
                $violations[] = 'cleanup did not observe any isolated Redis keys';
            }
            if ($cleanup['redis_keys_deleted'] !== $cleanup['redis_keys_before'] || $cleanup['redis_keys_after'] !== 0) {
                $violations[] = 'isolated Redis cleanup was incomplete';
            }
            if ($cleanup['database_before'] !== 1 || $cleanup['database_after'] !== 0) {
                $violations[] = 'isolated MySQL cleanup was incomplete';
            }
        } else {
            $violations[] = 'fixture cleanup evidence is unavailable';
        }

        return [
            'schema_version' => 1,
            'passed' => $fatalError === null && $violations === [],
            'configuration' => $this->options->toArray(),
            'stages' => $stageResults,
            'evidence' => $evidence,
            'cleanup' => $cleanup,
            'violations' => $violations,
            'fatal_error' => $fatalError,
            'worker_log_tails' => array_slice($workerLogs, 0, 3),
        ];
    }

    /**
     * @param list<string> $baseUrls
     * @param array<string, ServingLoadThreshold> $thresholds
     * @return array<string, ServingLoadBatchResult>
     */
    private function flow(
        ConcurrentHttpLoadClient $client,
        array $baseUrls,
        RedisBacklogAcceptanceHarness $harness,
        string $runToken,
        int $count,
        int $concurrency,
        array $thresholds,
    ): array {
        $serveRequests = [];
        for ($index = 0; $index < $count; ++$index) {
            $viewerId = 'load-viewer-' . $runToken . '-' . $index;
            $serveRequests[] = $this->jsonRequest(
                $this->baseUrl($baseUrls, $index) . '/api/v1/ads/serve',
                'POST',
                'serve-' . $runToken . '-' . $index,
                [
                    'site_id' => $harness->siteId(),
                    'slot_id' => $harness->slotId(),
                    'viewer_id' => $viewerId,
                    'size' => ['width' => 300, 'height' => 250],
                    'debug' => false,
                ],
                ['viewer_id' => $viewerId],
            );
        }
        $serve = $client->run(
            'serve',
            $serveRequests,
            $concurrency,
            $thresholds['serve'],
            function (array $response, array $request): array {
                $data = $this->jsonData($response, 200);
                if (($data['filled'] ?? false) !== true || !is_string($data['decision_id'] ?? null)) {
                    throw new \RuntimeException('Serve response was not a filled decision.');
                }

                return [
                    'decision_id' => $data['decision_id'],
                    'viewer_id' => (string) $request['context']['viewer_id'],
                ];
            },
        );
        if ($serve->metrics->errors !== 0) {
            return ['serve' => $serve];
        }
        $decisions = $this->completeOutputs($serve, $count, 'serve');

        $trackRequests = [];
        foreach ($decisions as $index => $decision) {
            $trackRequests[] = $this->jsonRequest(
                $this->baseUrl($baseUrls, $index) . '/api/v1/ads/track',
                'POST',
                'track-' . $runToken . '-' . $index,
                [
                    'decision_id' => $decision['decision_id'],
                    'viewer_id' => $decision['viewer_id'],
                    'event_id' => 'imp-' . $runToken . '-' . $index,
                    'visible_ratio' => 0.75,
                    'visible_ms' => 1_500,
                ],
            );
        }
        $track = $client->run(
            'track',
            $trackRequests,
            $concurrency,
            $thresholds['track'],
            function (array $response): bool {
                $data = $this->jsonData($response, 200);
                if (($data['accepted'] ?? false) !== true || ($data['duplicate'] ?? true) !== false) {
                    throw new \RuntimeException('Track response did not accept a unique impression.');
                }

                return true;
            },
        );
        if ($track->metrics->errors !== 0) {
            return ['serve' => $serve, 'track' => $track];
        }
        $this->completeOutputs($track, $count, 'track');

        $clickRequests = [];
        foreach ($decisions as $index => $decision) {
            $clickRequests[] = [
                'url' => $this->baseUrl($baseUrls, $index) . '/api/v1/ads/click?' . http_build_query([
                    'decision_id' => $decision['decision_id'],
                    'viewer_id' => $decision['viewer_id'],
                    'event_id' => 'clk-' . $runToken . '-' . $index,
                ], '', '&', PHP_QUERY_RFC3986),
                'method' => 'GET',
                'headers' => $this->headers('click-' . $runToken . '-' . $index),
                'label' => 'click-' . $index,
            ];
        }
        $click = $client->run(
            'click',
            $clickRequests,
            $concurrency,
            $thresholds['click'],
            static function (array $response): bool {
                if ($response['status'] !== 302) {
                    throw new \RuntimeException('Click response status was not 302.');
                }
                if (($response['headers']['location'] ?? null) !== self::LANDING_URL) {
                    throw new \RuntimeException('Click response location did not match the isolated fixture landing URL.');
                }

                return true;
            },
        );
        if ($click->metrics->errors !== 0) {
            return ['serve' => $serve, 'track' => $track, 'click' => $click];
        }
        $this->completeOutputs($click, $count, 'click');

        return ['serve' => $serve, 'track' => $track, 'click' => $click];
    }

    private function primeCanonicalGeo(RedisBacklogAcceptanceHarness $harness): void
    {
        $container = $harness->app()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('The load fixture application container is unavailable.');
        }
        $repository = $container->get(IpGeoRepositoryInterface::class);
        $resolvedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $repository->markResolved(new GeoIpRecord(
            ipAddress: '127.0.0.1',
            countryCode: 'US',
            countryName: 'United States',
            regionCode: 'LOAD',
            regionName: 'Load Test',
            cityName: 'Loopback',
            latitude: null,
            longitude: null,
            timezone: 'UTC',
            providerId: 'serving-load-fixture',
            resolvedAt: $resolvedAt,
            rawPayloadHash: hash('sha256', 'serving-load-fixture:127.0.0.1'),
            rawPayloadSummary: ['source' => 'isolated-load-fixture'],
        ));
        if ($repository->findResolved('127.0.0.1') === null) {
            throw new \RuntimeException('The canonical geo hot-path fixture could not be read back.');
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @return array{url:string,method:string,headers:list<string>,body:string,label:string,context:array<string,mixed>}
     */
    private function jsonRequest(
        string $url,
        string $method,
        string $requestId,
        array $body,
        array $context = [],
    ): array {
        return [
            'url' => $url,
            'method' => $method,
            'headers' => $this->headers($requestId, true),
            'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'label' => strtolower($method) . '-' . $requestId,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    private function headers(string $requestId, bool $json = false): array
    {
        $headers = [
            'Accept: application/json',
            'Connection: keep-alive',
            'User-Agent: VertoAD-Serving-Load/1.0',
            'X-Request-Id: ' . $requestId,
        ];
        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function jsonData(array $response, int $expectedStatus): array
    {
        if ($response['status'] !== $expectedStatus) {
            throw new \RuntimeException('Unexpected HTTP status ' . $response['status'] . '.');
        }
        $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_array($payload['data'] ?? null) || ($payload['error'] ?? null) !== null) {
            throw new \RuntimeException('Response did not contain a successful API envelope.');
        }

        return $payload['data'];
    }

    /** @return list<mixed> */
    private function completeOutputs(ServingLoadBatchResult $result, int $expected, string $stage): array
    {
        if ($result->metrics->errors !== 0 || count($result->outputs) !== $expected) {
            throw new \RuntimeException($stage . ' stage did not produce one successful output per request.');
        }
        foreach ($result->outputs as $output) {
            if ($output === null) {
                throw new \RuntimeException($stage . ' stage produced an empty output.');
            }
        }

        return $result->outputs;
    }

    /** @param list<string> $baseUrls */
    private function baseUrl(array $baseUrls, int $index): string
    {
        if ($baseUrls === []) {
            throw new \LogicException('The serving load worker pool is empty.');
        }

        return $baseUrls[$index % count($baseUrls)];
    }

    /** @return array<string, string> */
    private function workerEnvironment(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            throw new \RuntimeException('Unable to read the process environment for HTTP workers.');
        }
        $environment = array_map(static fn (mixed $value): string => (string) $value, $environment);
        $environment['APP_ENV'] = 'testing';
        $environment['APP_DEBUG'] = 'false';
        $environment['APP_INSTALLED'] = 'true';
        $environment['VERTOAD_INSTALL_TEST_BYPASS'] = 'true';
        $environment['IP_GEO_REPOSITORY'] = 'database';
        $environment['R2_PUBLIC_BASE_URL'] = 'https://assets.example.test';
        $environment['CLOUDFLARE_TRUSTED_PROXIES'] = '';

        return $environment;
    }
}
