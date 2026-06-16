<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;
use VertoAD\Service\IpGeo\MappedIpGeoResponseNormalizer;
use VertoAD\Service\Operations\RepositoryRealTimeGeoLookup;
use VertoAD\Service\Operations\StubRealTimeGeoLookup;

final class RealTimeGeoLookupTest extends TestCase
{
    public function testRepositoryLookupReturnsResolvedCanonicalStoreRecord(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $record = new GeoIpRecord(
            ipAddress: '203.0.113.20',
            countryCode: 'CN',
            countryName: 'China',
            regionCode: 'SH',
            regionName: 'Shanghai',
            cityName: null,
            latitude: 31.2304,
            longitude: 121.4737,
            timezone: 'Asia/Shanghai',
            providerId: 'canonical-provider',
            resolvedAt: new DateTimeImmutable('2026-06-08T13:30:00Z'),
            rawPayloadHash: hash('sha256', 'canonical'),
            rawPayloadSummary: ['country_code' => 'CN'],
        );
        $repository->markResolved($record);
        $lookup = $this->repositoryLookup($repository, new IpGeoProviderPolicy(enabled: false));

        $result = $lookup->lookup('203.0.113.20', 'req-canonical');

        self::assertSame('203.0.113.20', $result['ip_address']);
        self::assertSame('CN-SH', $result['canonical_geo_code']);
        self::assertSame('CN', $result['country_code']);
        self::assertSame('China', $result['country_name']);
        self::assertSame('SH', $result['region_code']);
        self::assertSame('Shanghai', $result['region']);
        self::assertSame(31.2304, $result['latitude']);
        self::assertSame(121.4737, $result['longitude']);
        self::assertSame('canonical-provider', $result['provider_id']);
        self::assertSame('canonical_store', $result['source']);
        self::assertSame('resolved', $result['status']);
        self::assertSame('2026-06-08T13:30:00+00:00', $result['queried_at']);
        self::assertTrue($result['persisted_to_canonical_store']);
    }

    public function testRepositoryLookupQueuesUnknownIpWhenPolicyIsDisabled(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $lookup = $this->repositoryLookup($repository, new IpGeoProviderPolicy(enabled: false));

        $result = $lookup->lookup('203.0.113.21', 'req-queued');

        self::assertSame('203.0.113.21', $result['ip_address']);
        self::assertNull($result['canonical_geo_code']);
        self::assertNull($result['country_code']);
        self::assertNull($result['country_name']);
        self::assertNull($result['region_code']);
        self::assertNull($result['region']);
        self::assertNull($result['city']);
        self::assertNull($result['latitude']);
        self::assertNull($result['longitude']);
        self::assertNull($result['timezone']);
        self::assertNull($result['provider_id']);
        self::assertSame('queued', $result['source']);
        self::assertSame('pending', $result['status']);
        self::assertFalse($result['persisted_to_canonical_store']);

        $queued = array_values($repository->rows())[0];
        self::assertSame('pending', $queued['status']);
        self::assertSame('operations_realtime_lookup', $queued['source']);
        self::assertSame('req-queued', $queued['request_id']);
        self::assertSame(['req-queued'], $queued['request_ids']);
    }

    public function testRepositoryLookupResolvesViaProviderAndPersistsCanonicalRecord(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $provider = $this->provider();
        $policy = new IpGeoProviderPolicy(enabled: true, providers: [$provider]);
        $transportCalls = [];
        $lookup = $this->repositoryLookup(
            $repository,
            $policy,
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static function (string $url, array $headers, int $timeoutSeconds) use (&$transportCalls): array {
                    $transportCalls[] = [$url, $headers, $timeoutSeconds];

                    return [
                        'status' => 200,
                        'body' => json_encode([
                            'countryCode' => 'CN',
                            'countryName' => 'China',
                            'regionCode' => 'SH',
                            'regionName' => 'Shanghai',
                            'timezone' => 'Asia/Shanghai',
                            'latitude' => '31.2304',
                            'longitude' => '121.4737',
                        ], JSON_THROW_ON_ERROR),
                    ];
                },
            ),
        );

        $result = $lookup->lookup('203.0.113.22', 'req-provider');

        self::assertSame('CN-SH', $result['canonical_geo_code']);
        self::assertSame('China', $result['country_name']);
        self::assertSame('Shanghai', $result['region']);
        self::assertNull($result['city']);
        self::assertSame(31.2304, $result['latitude']);
        self::assertSame(121.4737, $result['longitude']);
        self::assertSame('ops-test', $result['provider_id']);
        self::assertSame('provider', $result['source']);
        self::assertSame('resolved', $result['status']);
        self::assertTrue($result['persisted_to_canonical_store']);
        self::assertCount(1, $transportCalls);
        self::assertSame('https://geo.example.test/203.0.113.22', $transportCalls[0][0]);
        self::assertSame(2, $transportCalls[0][2]);

        $queued = array_values($repository->rows())[0];
        self::assertSame('resolved', $queued['status']);
        self::assertSame('req-provider', $queued['request_id']);
        self::assertSame(['req-provider'], $queued['request_ids']);
        self::assertSame('ops-test', $queued['provider_id']);
        self::assertNotNull($repository->findResolved('203.0.113.22'));
    }

    public function testRepositoryLookupFallsBackToBackupProviderWhenPrimaryFails(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'default_country_code' => 'CN',
            'providers' => [
                [
                    'id' => 'ops-primary-down',
                    'endpoint_template' => 'https://primary.example.test/{ip}',
                    'regions' => ['global'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'countryCode'],
                ],
                [
                    'id' => 'ops-backup-ok',
                    'endpoint_template' => 'https://backup.example.test/{ip}',
                    'regions' => ['global'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'countryCode', 'region_code' => 'regionCode'],
                ],
            ],
        ]);
        $orderedProviderIds = array_map(
            static fn (object $provider): string => $provider->id,
            (new IpGeoProviderSelector($policy))->orderedProviders(null, '203.0.113.22'),
        );
        $transportCalls = [];
        $lookup = $this->repositoryLookup(
            $repository,
            $policy,
            new MappedHttpIpGeoProviderClient(
                new MappedIpGeoResponseNormalizer(),
                static function (string $url) use (&$transportCalls): array {
                    $transportCalls[] = $url;
                    if (count($transportCalls) === 1) {
                        return ['status' => 503, 'body' => 'down'];
                    }

                    return [
                        'status' => 200,
                        'body' => json_encode(['regionCode' => 'SH'], JSON_THROW_ON_ERROR),
                    ];
                },
            ),
        );

        $result = $lookup->lookup('203.0.113.22', 'req-ops-failover');

        self::assertSame('CN-SH', $result['canonical_geo_code']);
        self::assertSame($orderedProviderIds[1], $result['provider_id']);
        self::assertSame('provider', $result['source']);
        self::assertCount(2, $transportCalls);
        self::assertNotSame($transportCalls[0], $transportCalls[1]);
    }

    public function testRepositoryLookupQueuesUnknownShapeWhenProviderSelectionFails(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $lookup = $this->repositoryLookup($repository, new IpGeoProviderPolicy(enabled: true, providers: []));

        $result = $lookup->lookup('203.0.113.23', 'req-provider-failed');

        self::assertSame('queued', $result['source']);
        self::assertSame('pending', $result['status']);
        self::assertFalse($result['persisted_to_canonical_store']);
        self::assertNull($result['canonical_geo_code']);

        $queued = array_values($repository->rows())[0];
        self::assertSame('pending', $queued['status']);
        self::assertSame('req-provider-failed', $queued['request_id']);
        self::assertSame(['req-provider-failed'], $queued['request_ids']);
    }

    public function testRepositoryLookupRejectsInvalidIpInput(): void
    {
        $lookup = $this->repositoryLookup(new InMemoryIpGeoRepository(), new IpGeoProviderPolicy(enabled: false));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ip_address must be a valid IP address.');

        $lookup->lookup('not-an-ip');
    }

    public function testStubLookupReturnsFixedUnknownCanonicalShape(): void
    {
        $lookup = new StubRealTimeGeoLookup();

        $result = $lookup->lookup(' 203.0.113.24 ', 'req-stub');

        self::assertSame('203.0.113.24', $result['ip_address']);
        self::assertNull($result['canonical_geo_code']);
        self::assertNull($result['country_code']);
        self::assertNull($result['region_code']);
        self::assertNull($result['region']);
        self::assertNull($result['city']);
        self::assertNull($result['latitude']);
        self::assertNull($result['longitude']);
        self::assertNull($result['timezone']);
        self::assertNull($result['provider_id']);
        self::assertSame('operations-stub', $result['source']);
        self::assertFalse($result['persisted_to_canonical_store']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $result['queried_at']);
    }

    public function testStubLookupRejectsInvalidIpInput(): void
    {
        $lookup = new StubRealTimeGeoLookup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ip_address must be a valid IP address.');

        $lookup->lookup('not-an-ip');
    }

    private function repositoryLookup(
        InMemoryIpGeoRepository $repository,
        IpGeoProviderPolicy $policy,
        ?MappedHttpIpGeoProviderClient $client = null,
    ): RepositoryRealTimeGeoLookup {
        return new RepositoryRealTimeGeoLookup(
            $repository,
            new IpGeoProviderSelector($policy),
            $client ?? new MappedHttpIpGeoProviderClient(new MappedIpGeoResponseNormalizer()),
            $policy,
        );
    }

    private function provider(): IpGeoProviderDefinition
    {
        return new IpGeoProviderDefinition(
            id: 'ops-test',
            endpointTemplate: 'https://geo.example.test/{ip}',
            fieldMap: [
                'country_code' => 'countryCode',
                'country_name' => 'countryName',
                'region_code' => 'regionCode',
                'region_name' => 'regionName',
                'latitude' => 'latitude',
                'longitude' => 'longitude',
                'timezone' => 'timezone',
            ],
            regions: ['global'],
            timeoutSeconds: 2,
        );
    }
}
