<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Service\IpGeo\AsyncIpGeoResolver;

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
}
