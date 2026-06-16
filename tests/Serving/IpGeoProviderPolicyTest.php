<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedIpGeoResponseNormalizer;

final class IpGeoProviderPolicyTest extends TestCase
{
    public function testRegionRoutingAndWeightedSelectionAreDeterministic(): void
    {
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'providers' => [
                [
                    'id' => 'global-provider',
                    'endpoint_template' => 'https://global.example/geo/{ip}',
                    'regions' => ['global'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code'],
                ],
                [
                    'id' => 'cn-primary',
                    'endpoint_template' => 'https://cn-primary.example/geo/{ip}',
                    'regions' => ['CN'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code'],
                ],
                [
                    'id' => 'cn-heavy',
                    'endpoint_template' => 'https://cn-heavy.example/geo/{ip}',
                    'regions' => ['CN'],
                    'weight' => 5,
                    'fields' => ['country_code' => 'country_code'],
                ],
            ],
        ]);
        $selector = new IpGeoProviderSelector($policy);

        $first = $selector->select('CN', '198.51.100.10');
        $second = $selector->select('CN', '198.51.100.10');
        self::assertSame($first->id, $second->id);
        self::assertContains($first->id, ['cn-primary', 'cn-heavy']);

        $counts = ['cn-primary' => 0, 'cn-heavy' => 0];
        for ($i = 1; $i <= 60; ++$i) {
            $selected = $selector->select('CN', '198.51.100.' . $i);
            ++$counts[$selected->id];
        }
        self::assertGreaterThan($counts['cn-primary'], $counts['cn-heavy']);

        self::assertSame('global-provider', $selector->select('US', '198.51.100.10')->id);
    }

    public function testCustomProviderMappingNormalizesCanonicalRecord(): void
    {
        $definition = new IpGeoProviderDefinition(
            id: 'custom-us',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: [
                'country_code' => 'payload.cc',
                'country_name' => 'payload.country',
                'region_code' => 'payload.state.code',
                'region_name' => 'payload.state.name',
                'city_name' => 'payload.city',
                'latitude' => 'payload.lat',
                'longitude' => 'payload.lng',
                'timezone' => 'payload.tz',
            ],
        );
        $normalizer = new MappedIpGeoResponseNormalizer();

        $record = $normalizer->normalize(
            '203.0.113.8',
            $definition,
            [
                'payload' => [
                    'cc' => 'us',
                    'country' => 'United States',
                    'state' => ['code' => 'ca', 'name' => 'California'],
                    'city' => 'San Francisco',
                    'lat' => '37.7749',
                    'lng' => '-122.4194',
                    'tz' => 'America/Los_Angeles',
                ],
            ],
            new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
        );

        self::assertSame('203.0.113.8', $record->ipAddress);
        self::assertSame('US', $record->countryCode);
        self::assertSame('United States', $record->countryName);
        self::assertSame('CA', $record->regionCode);
        self::assertSame('California', $record->regionName);
        self::assertSame('San Francisco', $record->cityName);
        self::assertSame(37.7749, $record->latitude);
        self::assertSame(-122.4194, $record->longitude);
        self::assertSame('America/Los_Angeles', $record->timezone);
        self::assertSame('custom-us', $record->providerId);
        self::assertSame('US-CA-SAN-FRANCISCO', $record->canonicalGeoCode());
        self::assertNotSame('', $record->rawPayloadHash);
        self::assertArrayHasKey('payload.cc', $record->rawPayloadSummary);
    }

    public function testPolicyRejectsPlaintextApiKeysButAllowsApiKeyEnvironmentVariableNames(): void
    {
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'providers' => [[
                'id' => 'keyed-provider',
                'endpoint_template' => 'https://geo.example/lookup/{ip}?key={api_key}',
                'api_key_env_var' => 'IP_GEO_PROVIDER_KEY',
                'fields' => ['country_code' => 'country_code'],
            ]],
        ]);

        self::assertSame('IP_GEO_PROVIDER_KEY', $policy->provider('keyed-provider')?->apiKeyEnvVar);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider API keys must stay in environment variables.');
        IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'providers' => [[
                'id' => 'bad-provider',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'api_key' => 'must-not-be-stored',
                'fields' => ['country_code' => 'country_code'],
            ]],
        ]);
    }
}
