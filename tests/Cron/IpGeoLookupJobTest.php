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
