<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Service\IpGeo\AsyncIpGeoResolver;
use VertoAD\Service\Serving\NullGeoResolver;

final class AsyncIpGeoResolverTest extends TestCase
{
    public function testServePathQueuesUnknownIpWithoutBlockingForProviderLookup(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $resolver = new AsyncIpGeoResolver($repository, 'serving');

        $context = $resolver->contextForIpGeoRequest(
            '198.51.100.10',
            'Geo test browser',
            'US',
            'req-serve-geo-1',
        );

        self::assertSame('198.51.100.10', $context->ipAddress);
        self::assertSame('Geo test browser', $context->userAgent);
        self::assertNull($context->geoCode);
        self::assertNull($context->geoRecord);

        $rows = $repository->rows();
        self::assertCount(1, $rows);
        $queued = array_values($rows)[0];
        self::assertSame('pending', $queued['status']);
        self::assertSame('198.51.100.10', $queued['ip_address']);
        self::assertSame('Geo test browser', $queued['user_agent']);
        self::assertSame('serving', $queued['source']);
        self::assertSame('req-serve-geo-1', $queued['request_id']);
    }

    public function testResolvedRecordProvidesCanonicalGeoCodeWithoutRequeueing(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $record = new GeoIpRecord(
            ipAddress: '198.51.100.20',
            countryCode: 'cn',
            countryName: 'China',
            regionCode: 'sh',
            regionName: 'Shanghai',
            cityName: null,
            latitude: 31.2304,
            longitude: 121.4737,
            timezone: 'Asia/Shanghai',
            providerId: 'ip-sb',
            resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            rawPayloadHash: hash('sha256', '{"country_code":"CN"}'),
            rawPayloadSummary: ['country_code' => 'CN'],
        );
        $repository->markResolved($record);
        $resolver = new AsyncIpGeoResolver($repository, 'serving');

        $context = $resolver->contextForRequest('198.51.100.20', 'Geo test browser');

        self::assertSame('CN-SH', $context->geoCode);
        self::assertSame($record, $context->geoRecord);
        self::assertCount(1, $repository->rows());
    }

    public function testInvalidIpIsIgnoredAndNotQueued(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $resolver = new AsyncIpGeoResolver($repository, 'serving');

        $context = $resolver->contextForRequest('not-an-ip', 'Geo test browser');

        self::assertNull($context->ipAddress);
        self::assertNull($context->geoCode);
        self::assertSame([], $repository->rows());
    }

    public function testNullAndBlankIpInputsAreIgnoredAndResolveDelegatesToContext(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $resolver = new AsyncIpGeoResolver($repository, 'serving');

        $nullContext = $resolver->contextForRequest(null, 'Geo test browser', 'req-null-ip');
        $blankContext = $resolver->contextForRequest('   ', 'Geo test browser', 'req-blank-ip');

        self::assertNull($nullContext->ipAddress);
        self::assertSame('req-null-ip', $nullContext->requestId);
        self::assertNull($blankContext->ipAddress);
        self::assertSame('req-blank-ip', $blankContext->requestId);
        self::assertNull($resolver->resolve('not-an-ip', 'Geo test browser'));
        self::assertSame([], $repository->rows());
    }

    public function testQueueFailuresDoNotBlockServingContext(): void
    {
        $resolver = new AsyncIpGeoResolver(new class implements IpGeoRepositoryInterface {
            public function findResolved(string $ipAddress): ?GeoIpRecord
            {
                return null;
            }

            public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
            {
                throw new \RuntimeException('queue failed');
            }

            public function searchLookups(array $filters): array
            {
                return [];
            }

            public function lookupQueueSummary(): array
            {
                return [
                    'counts' => ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead' => 0, 'resolved' => 0, 'total' => 0],
                    'oldest_pending_at' => null,
                    'next_retry_at' => null,
                    'latest_failure' => null,
                    'recent_tasks' => [],
                ];
            }

            public function leasePending(int $limit, DateTimeImmutable $now): array
            {
                return [];
            }

            public function markResolved(GeoIpRecord $record, ?string $leaseToken = null): void
            {
            }

            public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds, ?string $leaseToken = null): void
            {
            }
        }, 'serving');

        $context = $resolver->contextForRequest('203.0.113.42', 'Geo test browser', 'req-queue-failure');

        self::assertSame('203.0.113.42', $context->ipAddress);
        self::assertNull($context->geoCode);
        self::assertNull($context->geoRecord);
        self::assertSame('req-queue-failure', $context->requestId);
    }

    public function testRepositoryLookupFailuresDoNotBlockServingContext(): void
    {
        $resolver = new AsyncIpGeoResolver(new class implements IpGeoRepositoryInterface {
            public function findResolved(string $ipAddress): ?GeoIpRecord
            {
                throw new \RuntimeException('lookup failed');
            }

            public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
            {
                throw new \RuntimeException('ensureQueued should not be reached');
            }

            public function searchLookups(array $filters): array
            {
                return [];
            }

            public function lookupQueueSummary(): array
            {
                return [
                    'counts' => ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead' => 0, 'resolved' => 0, 'total' => 0],
                    'oldest_pending_at' => null,
                    'next_retry_at' => null,
                    'latest_failure' => null,
                    'recent_tasks' => [],
                ];
            }

            public function leasePending(int $limit, DateTimeImmutable $now): array
            {
                return [];
            }

            public function markResolved(GeoIpRecord $record, ?string $leaseToken = null): void
            {
            }

            public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds, ?string $leaseToken = null): void
            {
            }
        }, 'serving');

        $context = $resolver->contextForRequest('203.0.113.40', 'Geo test browser', 'req-resolver-failure');

        self::assertSame('203.0.113.40', $context->ipAddress);
        self::assertNull($context->geoCode);
        self::assertNull($context->geoRecord);
        self::assertSame('req-resolver-failure', $context->requestId);
    }

    public function testContextForIpGeoRequestAcceptsNullRegionHintAndExplicitRequestId(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $resolver = new AsyncIpGeoResolver($repository, 'serving');

        $context = $resolver->contextForIpGeoRequest('203.0.113.41', 'Geo test browser', null, 'req-explicit');

        self::assertSame('203.0.113.41', $context->ipAddress);
        self::assertNull($context->geoCode);
        self::assertNull($context->geoRecord);
        self::assertSame('req-explicit', $context->requestId);
        self::assertSame('pending', array_values($repository->rows())[0]['status']);
    }

    public function testValueObjectsRejectInvalidIpGeoState(): void
    {
        try {
            new GeoIpRecord(
                ipAddress: 'not-an-ip',
                countryCode: 'CN',
                countryName: 'China',
                regionCode: null,
                regionName: null,
                cityName: null,
                latitude: null,
                longitude: null,
                timezone: null,
                providerId: 'provider',
                resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
                rawPayloadHash: hash('sha256', '{}'),
                rawPayloadSummary: [],
            );
            self::fail('Expected invalid GeoIpRecord IP to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Geo IP record requires a valid IP address.', $exception->getMessage());
        }

        try {
            new GeoIpRecord(
                ipAddress: '203.0.113.70',
                countryCode: 'CN',
                countryName: 'China',
                regionCode: null,
                regionName: null,
                cityName: null,
                latitude: null,
                longitude: null,
                timezone: null,
                providerId: ' ',
                resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
                rawPayloadHash: hash('sha256', '{}'),
                rawPayloadSummary: [],
            );
            self::fail('Expected blank GeoIpRecord provider id to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Geo IP record provider id is required.', $exception->getMessage());
        }

        try {
            new GeoIpRecord(
                ipAddress: '203.0.113.71',
                countryCode: 'CN',
                countryName: 'China',
                regionCode: null,
                regionName: null,
                cityName: null,
                latitude: null,
                longitude: null,
                timezone: null,
                providerId: 'provider',
                resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
                rawPayloadHash: ' ',
                rawPayloadSummary: [],
            );
            self::fail('Expected blank GeoIpRecord payload hash to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Geo IP record raw payload hash is required.', $exception->getMessage());
        }

        try {
            GeoIpRecord::hashIp('bad-ip');
            self::fail('Expected invalid IP hashing to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Cannot hash invalid IP address.', $exception->getMessage());
        }

        try {
            new GeoIpLookupTask('bad-ip', null, null, 0, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected invalid lookup task IP to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo lookup task requires a valid IP address.', $exception->getMessage());
        }

        try {
            new GeoIpLookupTask('203.0.113.72', null, null, -1, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected negative attempts to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo lookup attempts must be non-negative.', $exception->getMessage());
        }

        try {
            new GeoIpLookupTask('203.0.113.73', null, null, 0, new DateTimeImmutable('2026-06-16T00:00:00+00:00'), requestIds: ['']);
            self::fail('Expected blank request ids to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo lookup task request IDs must be non-empty strings.', $exception->getMessage());
        }

        $nullGeoResolver = new NullGeoResolver();
        self::assertNull($nullGeoResolver->resolve('203.0.113.74', 'Geo test browser'));
        self::assertSame('203.0.113.74', $nullGeoResolver->contextForRequest('203.0.113.74', 'Geo test browser', 'req-null-geo')->ipAddress);
    }
}
