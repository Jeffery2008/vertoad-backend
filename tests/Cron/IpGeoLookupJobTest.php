<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Service\Cron\IpGeoLookupJob;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;
use VertoAD\Service\IpGeo\MappedIpGeoResponseNormalizer;

final class IpGeoLookupJobTest extends TestCase
{
    public function testDisabledPolicyCompletesWithoutLeasingPendingIps(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $repository->ensureQueued(
            '203.0.113.10',
            'UA',
            'US',
            'serving',
            new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            'req-cron-disabled',
        );
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => false,
            'batch_size' => 10,
            'providers' => [[
                'id' => 'disabled-provider',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'regions' => ['US'],
                'fields' => ['country_code' => 'country_code'],
            ]],
        ]);
        $transportCalled = false;
        $job = new IpGeoLookupJob(
            $repository,
            new IpGeoProviderSelector($policy),
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static function () use (&$transportCalled): array {
                    $transportCalled = true;

                    return ['status' => 200, 'body' => '{}'];
                },
            ),
            $policy,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-16T00:00:05+00:00'),
        );

        $result = $job->run();
        $row = array_values($repository->rows())[0];

        self::assertSame('completed', $result->status);
        self::assertSame([
            'leased' => 0,
            'resolved' => 0,
            'failed' => 0,
            'disabled' => 1,
        ], $result->metrics);
        self::assertFalse($transportCalled);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, $row['attempts']);
        self::assertSame('req-cron-disabled', $row['request_id']);
    }

    public function testCronConsumesPendingIpsAndWritesCanonicalRecords(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.8', 'UA', 'US', 'serving', $queuedAt, 'req-cron-geo-ok');
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'batch_size' => 10,
            'providers' => [[
                'id' => 'custom-us',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'regions' => ['US'],
                'fields' => [
                    'country_code' => 'country_code',
                    'country_name' => 'country_name',
                    'region_code' => 'region.code',
                    'region_name' => 'region.name',
                    'city_name' => 'city',
                    'latitude' => 'lat',
                    'longitude' => 'lng',
                    'timezone' => 'timezone',
                ],
            ]],
        ]);
        $requests = [];
        $job = new IpGeoLookupJob(
            $repository,
            new IpGeoProviderSelector($policy),
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static function (string $url, array $headers, int $timeoutSeconds) use (&$requests): array {
                    $requests[] = [$url, $headers, $timeoutSeconds];

                    return [
                        'status' => 200,
                        'body' => json_encode([
                            'country_code' => 'us',
                            'country_name' => 'United States',
                            'region' => ['code' => 'ca', 'name' => 'California'],
                            'city' => 'San Francisco',
                            'lat' => '37.7749',
                            'lng' => '-122.4194',
                            'timezone' => 'America/Los_Angeles',
                        ], JSON_THROW_ON_ERROR),
                    ];
                },
            ),
            $policy,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-16T00:00:05+00:00'),
        );

        $result = $job->run();
        $record = $repository->findResolved('203.0.113.8');

        self::assertSame('ip-geo-resolve', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->metrics['leased'] ?? null);
        self::assertSame(1, $result->metrics['resolved'] ?? null);
        self::assertSame(0, $result->metrics['failed'] ?? null);
        self::assertNotNull($record);
        self::assertSame('custom-us', $record->providerId);
        self::assertSame('US-CA-SAN-FRANCISCO', $record->canonicalGeoCode());
        self::assertSame('https://geo.example/lookup/203.0.113.8', $requests[0][0]);

        $row = array_values($repository->rows())[0];
        self::assertSame('req-cron-geo-ok', $row['request_id']);
    }

    public function testCronFallsBackToNextProviderWhenSelectedProviderFails(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.12', 'UA', 'US', 'serving', $queuedAt, 'req-cron-failover');
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'batch_size' => 10,
            'default_country_code' => 'US',
            'providers' => [
                [
                    'id' => 'primary-down',
                    'endpoint_template' => 'https://primary.example/lookup/{ip}',
                    'regions' => ['US'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code', 'region_code' => 'region_code'],
                ],
                [
                    'id' => 'backup-ok',
                    'endpoint_template' => 'https://backup.example/lookup/{ip}',
                    'regions' => ['US'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code', 'region_code' => 'region_code'],
                ],
            ],
        ]);
        $orderedProviderIds = array_map(
            static fn (object $provider): string => $provider->id,
            (new IpGeoProviderSelector($policy))->orderedProviders('US', '203.0.113.12'),
        );
        $requests = [];
        $job = new IpGeoLookupJob(
            $repository,
            new IpGeoProviderSelector($policy),
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static function (string $url) use (&$requests): array {
                    $requests[] = $url;
                    if (count($requests) === 1) {
                        return ['status' => 503, 'body' => 'unavailable'];
                    }

                    return [
                        'status' => 200,
                        'body' => json_encode(['region_code' => 'CA'], JSON_THROW_ON_ERROR),
                    ];
                },
            ),
            $policy,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-16T00:00:05+00:00'),
        );

        $result = $job->run();
        $record = $repository->findResolved('203.0.113.12');

        self::assertSame(1, $result->metrics['resolved'] ?? null);
        self::assertSame(0, $result->metrics['failed'] ?? null);
        self::assertNotNull($record);
        self::assertSame($orderedProviderIds[1], $record->providerId);
        self::assertSame('US-CA', $record->canonicalGeoCode());
        self::assertCount(2, $requests);
        self::assertNotSame($requests[0], $requests[1]);
    }

    public function testProviderFailuresAreRetriedWithBackoffAndLastError(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $repository->ensureQueued('203.0.113.9', 'UA', 'US', 'serving', new DateTimeImmutable('2026-06-16T00:00:00+00:00'), 'req-cron-geo-fail');
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'batch_size' => 10,
            'max_attempts' => 3,
            'retry_backoff_seconds' => 60,
            'providers' => [[
                'id' => 'failing-provider',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'regions' => ['US'],
                'fields' => ['country_code' => 'country_code'],
            ]],
        ]);
        $job = new IpGeoLookupJob(
            $repository,
            new IpGeoProviderSelector($policy),
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static fn (): array => ['status' => 503, 'body' => 'unavailable'],
            ),
            $policy,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-16T00:00:05+00:00'),
        );

        $result = $job->run();
        $rows = $repository->rows();
        $row = array_values($rows)[0];

        self::assertSame(1, $result->metrics['failed'] ?? null);
        self::assertSame('failed', $row['status']);
        self::assertSame(1, $row['attempts']);
        self::assertSame('req-cron-geo-fail', $row['request_id']);
        self::assertSame('failing-provider', $row['provider_id']);
        self::assertStringContainsString('failed with HTTP 503', $row['last_error']);
        self::assertInstanceOf(DateTimeImmutable::class, $row['next_attempt_at']);
        self::assertGreaterThan(new DateTimeImmutable('2026-06-16T00:00:05+00:00'), $row['next_attempt_at']);
    }
}
