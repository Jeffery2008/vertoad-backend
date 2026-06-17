<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;
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

    public function testProviderHeaderTemplatesRequireApiKeyEnvironmentVariableNames(): void
    {
        $provider = IpGeoProviderDefinition::fromArray([
            'id' => 'header-template-provider',
            'endpoint_template' => 'https://geo.example/lookup/{ip}',
            'api_key_env_var' => 'IP_GEO_HEADER_KEY',
            'headers' => [
                'Authorization' => 'Bearer {api_key}',
                'X-Static' => 'VertoAD',
            ],
            'fields' => ['country_code' => 'country_code'],
        ]);

        self::assertSame('Bearer {api_key}', $provider->headers['Authorization']);
        self::assertSame('IP_GEO_HEADER_KEY', $provider->apiKeyEnvVar);

        $this->assertInvalidProviderConfiguration(
            [
                'id' => 'missing-env-header-template',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'headers' => ['Authorization' => 'Bearer {api_key}'],
                'fields' => ['country_code' => 'country_code'],
            ],
            'IP geo provider headers using {api_key} require api_key_env_var.',
        );
        $this->assertInvalidProviderConfiguration(
            [
                'id' => 'plaintext-authorization-header',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'api_key_env_var' => 'IP_GEO_HEADER_KEY',
                'headers' => ['Authorization' => 'Bearer literal-secret'],
                'fields' => ['country_code' => 'country_code'],
            ],
            'Provider API keys must stay in environment variables.',
        );
        $this->assertInvalidProviderConfiguration(
            [
                'id' => 'plaintext-token-header',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'api_key_env_var' => 'IP_GEO_HEADER_KEY',
                'headers' => ['X-Provider-Token' => 'literal-secret'],
                'fields' => ['country_code' => 'country_code'],
            ],
            'Provider API keys must stay in environment variables.',
        );
    }

    public function testPconlineNormalizerDefaultsCountryAndMapsChinaRegionIdentifiers(): void
    {
        $provider = IpGeoProviderPolicy::default()->provider('pconline');
        self::assertNotNull($provider);
        $normalizer = new MappedIpGeoResponseNormalizer();
        $resolvedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');

        $numericRecord = $normalizer->normalize(
            '203.0.113.20',
            $provider,
            ['proCode' => '310000', 'pro' => '上海市', 'city' => '上海'],
            $resolvedAt,
        );
        self::assertSame('CN', $numericRecord->countryCode);
        self::assertSame('SH', $numericRecord->regionCode);
        self::assertSame('上海市', $numericRecord->regionName);
        self::assertSame('CN-SH-上海', $numericRecord->canonicalGeoCode());

        $aliasRecord = $normalizer->normalize(
            '203.0.113.21',
            $provider,
            ['pro' => '广东省', 'city' => '广州'],
            $resolvedAt,
        );
        self::assertSame('CN', $aliasRecord->countryCode);
        self::assertSame('GD', $aliasRecord->regionCode);
        self::assertSame('广东省', $aliasRecord->regionName);

        $fallbackRecord = $normalizer->normalize(
            '203.0.113.22',
            $provider,
            ['proCode' => '510000', 'pro' => '四川省'],
            $resolvedAt,
        );
        self::assertSame('510000', $fallbackRecord->regionCode);

        $missingRegionRecord = $normalizer->normalize(
            '203.0.113.25',
            $provider,
            ['city' => '未知'],
            $resolvedAt,
        );
        self::assertSame('CN', $missingRegionRecord->countryCode);
        self::assertNull($missingRegionRecord->regionCode);
    }

    public function testSparseProviderPayloadNormalizesMissingAndNonScalarValues(): void
    {
        $definition = new IpGeoProviderDefinition(
            id: 'sparse-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: [
                'country_code' => 'payload.country.code',
                'country_name' => 'payload.country.name',
                'region_code' => 'payload.region.code',
                'region_name' => 'payload.region.name',
                'city_name' => 'payload.city',
                'latitude' => 'payload.lat',
                'longitude' => 'payload.lng',
                'timezone' => 'payload.tz',
            ],
        );
        $normalizer = new MappedIpGeoResponseNormalizer();

        $record = $normalizer->normalize(
            '203.0.113.23',
            $definition,
            [
                'payload' => [
                    'country' => 'not-an-object',
                    'region' => ['name' => ['not scalar']],
                    'city' => null,
                    'lat' => 31,
                    'lng' => 121.5,
                    'tz' => false,
                ],
            ],
            new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
        );

        self::assertNull($record->countryCode);
        self::assertNull($record->countryName);
        self::assertNull($record->regionCode);
        self::assertNull($record->regionName);
        self::assertNull($record->cityName);
        self::assertSame(31.0, $record->latitude);
        self::assertSame(121.5, $record->longitude);
        self::assertNull($record->timezone);
        self::assertArrayHasKey('payload.country.code', $record->rawPayloadSummary);
        self::assertArrayHasKey('payload.region.code', $record->rawPayloadSummary);

        $invalidNumericRecord = $normalizer->normalize(
            '203.0.113.24',
            $definition,
            ['payload' => ['country' => ['code' => 'cn'], 'lat' => 'north', 'lng' => '']],
            new DateTimeImmutable('2026-06-16T00:00:01+00:00'),
        );

        self::assertSame('CN', $invalidNumericRecord->countryCode);
        self::assertNull($invalidNumericRecord->latitude);
        self::assertNull($invalidNumericRecord->longitude);
    }

    public function testDefaultCountryCodePolicyBackfillsSparseProviderPayloads(): void
    {
        $policy = IpGeoProviderPolicy::fromArray([
            'enabled' => true,
            'default_country_code' => 'cn',
            'providers' => [[
                'id' => 'default-country-provider',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'fields' => ['country_code' => 'country_code', 'region_code' => 'region_code'],
            ]],
        ]);
        $provider = $policy->provider('default-country-provider');
        self::assertNotNull($provider);
        self::assertSame('CN', $policy->defaultCountryCode);

        $record = (new MappedIpGeoResponseNormalizer())->normalize(
            '203.0.113.26',
            $provider,
            ['region_code' => 'SH'],
            new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            $policy->defaultCountryCode,
        );

        self::assertSame('CN', $record->countryCode);
        self::assertSame('CN-SH', $record->canonicalGeoCode());
    }

    public function testHttpProviderClientInterpolatesEndpointAndHeadersWithoutRealHttp(): void
    {
        $previous = getenv('IP_GEO_HTTP_TEST_KEY');
        putenv('IP_GEO_HTTP_TEST_KEY=unit test key');
        $captured = [];
        $provider = new IpGeoProviderDefinition(
            id: 'keyed-http-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}?key={api_key}',
            fieldMap: ['country_code' => 'country_code'],
            timeoutSeconds: 7,
            apiKeyEnvVar: 'IP_GEO_HTTP_TEST_KEY',
            headers: [
                'Authorization' => 'Bearer {api_key}',
                'X-Static' => 'VertoAD',
            ],
        );
        $client = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static function (string $url, array $headers, int $timeoutSeconds) use (&$captured): array {
                $captured = compact('url', 'headers', 'timeoutSeconds');

                return [
                    'status' => 200,
                    'body' => json_encode(['country_code' => 'us'], JSON_THROW_ON_ERROR),
                ];
            },
        );

        try {
            $record = $client->lookup('203.0.113.30', $provider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
        } finally {
            $previous === false ? putenv('IP_GEO_HTTP_TEST_KEY') : putenv('IP_GEO_HTTP_TEST_KEY=' . $previous);
        }

        self::assertSame('US', $record->countryCode);
        self::assertSame('https://geo.example/lookup/203.0.113.30?key=unit%20test%20key', $captured['url']);
        self::assertSame('Bearer unit test key', $captured['headers']['Authorization']);
        self::assertSame('VertoAD', $captured['headers']['X-Static']);
        self::assertSame(7, $captured['timeoutSeconds']);
    }

    public function testHttpProviderClientRejectsMissingApiKeyAndMalformedJsonResponses(): void
    {
        $previous = getenv('IP_GEO_MISSING_TEST_KEY');
        putenv('IP_GEO_MISSING_TEST_KEY');
        $provider = new IpGeoProviderDefinition(
            id: 'missing-key-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}?key={api_key}',
            fieldMap: ['country_code' => 'country_code'],
            apiKeyEnvVar: 'IP_GEO_MISSING_TEST_KEY',
        );
        $client = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static function (): array {
                self::fail('The provider transport must not run when the configured API key is missing.');
            },
        );

        try {
            $client->lookup('203.0.113.31', $provider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected the missing provider API key to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider missing-key-provider requires api_key_env_var IP_GEO_MISSING_TEST_KEY.', $exception->getMessage());
        } finally {
            $previous === false ? putenv('IP_GEO_MISSING_TEST_KEY') : putenv('IP_GEO_MISSING_TEST_KEY=' . $previous);
        }

        $previousHeaderKey = getenv('IP_GEO_MISSING_HEADER_TEST_KEY');
        putenv('IP_GEO_MISSING_HEADER_TEST_KEY');
        $headerOnlyProvider = new IpGeoProviderDefinition(
            id: 'missing-header-key-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: ['country_code' => 'country_code'],
            apiKeyEnvVar: 'IP_GEO_MISSING_HEADER_TEST_KEY',
            headers: ['Authorization' => 'Bearer {api_key}'],
        );
        $headerOnlyClient = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static function (): array {
                self::fail('The provider transport must not run when a header API key template is missing.');
            },
        );

        try {
            $headerOnlyClient->lookup('203.0.113.31', $headerOnlyProvider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected the missing header provider API key to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider missing-header-key-provider requires api_key_env_var IP_GEO_MISSING_HEADER_TEST_KEY.', $exception->getMessage());
        } finally {
            $previousHeaderKey === false ? putenv('IP_GEO_MISSING_HEADER_TEST_KEY') : putenv('IP_GEO_MISSING_HEADER_TEST_KEY=' . $previousHeaderKey);
        }

        $invalidJsonProvider = new IpGeoProviderDefinition(
            id: 'invalid-json-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: ['country_code' => 'country_code'],
        );
        $invalidJsonClient = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static fn (): array => ['status' => 200, 'body' => '{'],
        );
        try {
            $invalidJsonClient->lookup('203.0.113.32', $invalidJsonProvider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected malformed JSON to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider invalid-json-provider returned invalid JSON.', $exception->getMessage());
        }

        $scalarJsonClient = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static fn (): array => ['status' => 200, 'body' => '"CN"'],
        );
        try {
            $scalarJsonClient->lookup('203.0.113.33', $invalidJsonProvider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected scalar JSON to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider invalid-json-provider returned invalid JSON.', $exception->getMessage());
        }
    }

    public function testHttpProviderClientRejectsHttpFailureAndParsesTransportStatuses(): void
    {
        $provider = new IpGeoProviderDefinition(
            id: 'status-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: ['country_code' => 'country_code'],
        );
        $client = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static fn (): array => ['status' => 404, 'body' => '{}'],
        );

        try {
            $client->lookup('203.0.113.34', $provider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected HTTP 404 to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider status-provider failed with HTTP 404.', $exception->getMessage());
        }

        $emptyBodyClient = new MappedHttpIpGeoProviderClient(
            new MappedIpGeoResponseNormalizer(),
            static fn (): array => ['status' => 200, 'body' => ' '],
        );
        try {
            $emptyBodyClient->lookup('203.0.113.35', $provider, new DateTimeImmutable('2026-06-16T00:00:00+00:00'));
            self::fail('Expected an empty response body to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider status-provider failed with HTTP 200.', $exception->getMessage());
        }

        self::assertSame(404, MappedHttpIpGeoProviderClient::statusCodeFromHeaders(['HTTP/1.1 404 Not Found'], '{}'));
        self::assertSame(0, MappedHttpIpGeoProviderClient::statusCodeFromHeaders([], false));
        self::assertSame(200, MappedHttpIpGeoProviderClient::statusCodeFromHeaders([], '{}'));

        $transport = MappedHttpIpGeoProviderClient::httpTransport();
        self::assertSame(['status' => 200, 'body' => '{}'], $transport('data://text/plain,%7B%7D', ['X-Test' => 'ok'], 1));

        $missingFile = 'file:///' . str_replace('\\', '/', __DIR__) . '/missing-ip-geo-response.json';
        self::assertSame(['status' => 0, 'body' => ''], $transport($missingFile, [], 1));
    }

    public function testProviderValidationRejectsInvalidDefinitions(): void
    {
        $this->assertInvalidProviderConfiguration(
            ['id' => ' ', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code']],
            'IP geo provider id is required.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-endpoint', 'endpoint_template' => 'http://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code']],
            'IP geo provider endpoint_template must be an HTTPS URL template.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'empty-fields', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => []],
            'IP geo provider fields are required.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-weight', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'weight' => 0, 'fields' => ['country_code' => 'country_code']],
            'IP geo provider weight must be positive.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-timeout', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'timeout_seconds' => 0, 'fields' => ['country_code' => 'country_code']],
            'IP geo provider timeout_seconds must be positive.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-env', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'api_key_env_var' => 'bad-key', 'fields' => ['country_code' => 'country_code']],
            'IP geo provider api_key_env_var must be an environment variable name.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'unknown-field', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code'], 'unexpected' => true],
            'IP geo provider contains unknown field unexpected.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'non-array-fields', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => 'country_code'],
            'IP geo provider fields must be an object.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'non-array-regions', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code'], 'regions' => 'CN'],
            'IP geo provider regions must be a non-empty list.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'empty-regions', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code'], 'regions' => []],
            'IP geo provider regions must be a non-empty list.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'non-array-headers', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code'], 'headers' => 'X-Test'],
            'IP geo provider headers must be an object.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-fields-map', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => ' ']],
            'IP geo provider fields must map strings to strings.',
        );
        $this->assertInvalidProviderConfiguration(
            ['id' => 'bad-headers-map', 'endpoint_template' => 'https://geo.example/lookup/{ip}', 'fields' => ['country_code' => 'country_code'], 'headers' => ['X-Test' => ' ']],
            'IP geo provider headers must map strings to strings.',
        );
    }

    public function testPolicyValidationRejectsInvalidPolicyShapes(): void
    {
        $this->assertInvalidPolicyConfiguration(['providers' => [], 'batch_size' => 0], 'IP geo batch_size must be positive.');
        $this->assertInvalidPolicyConfiguration(['providers' => [], 'max_attempts' => 0], 'IP geo max_attempts must be positive.');
        $this->assertInvalidPolicyConfiguration(['providers' => [], 'retry_backoff_seconds' => 0], 'IP geo retry_backoff_seconds must be positive.');
        $this->assertInvalidPolicyConfiguration(['providers' => [], 'cache_ttl_seconds' => 0], 'IP geo cache_ttl_seconds must be positive.');
        $this->assertInvalidPolicyConfiguration(['providers' => 'not-a-list'], 'IP geo providers must be a list.');
        $this->assertInvalidPolicyConfiguration(['providers' => ['not-an-object']], 'IP geo provider must be an object.');
        $this->assertInvalidPolicyConfiguration(['unexpected' => true], 'IP geo provider policy contains unknown field unexpected.');
        $this->assertInvalidPolicyConfiguration(['providers' => [], 'default_country_code' => 'CHN'], 'IP geo default_country_code must be a two-letter country code.');

        try {
            new IpGeoProviderPolicy(providers: [new \stdClass()]);
            self::fail('Expected non-provider definitions to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo providers must be provider definitions.', $exception->getMessage());
        }

        $duplicateProvider = new IpGeoProviderDefinition(
            id: 'duplicate-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: ['country_code' => 'country_code'],
        );
        try {
            new IpGeoProviderPolicy(providers: [$duplicateProvider, $duplicateProvider]);
            self::fail('Expected duplicate provider ids to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Duplicate IP geo provider id duplicate-provider.', $exception->getMessage());
        }
    }

    public function testPolicyCanDisableBuiltinsOrIncludeThemWithCustomProviders(): void
    {
        $withoutBuiltins = IpGeoProviderPolicy::fromArray([
            'include_builtins' => false,
        ]);
        self::assertSame([], $withoutBuiltins->providers);

        $withBuiltins = IpGeoProviderPolicy::fromArray([
            'include_builtins' => true,
            'providers' => [[
                'id' => 'custom-cn',
                'endpoint_template' => 'https://geo.example/lookup/{ip}',
                'regions' => ['cn'],
                'fields' => ['country_code' => 'country_code'],
            ]],
            'queue_source' => '  ',
        ]);

        self::assertNotNull($withBuiltins->provider('pconline'));
        self::assertNotNull($withBuiltins->provider('custom-cn'));
        self::assertSame('serving', $withBuiltins->queueSource);
        $customProvider = $withBuiltins->provider('custom-cn');
        self::assertNotNull($customProvider);
        self::assertTrue($customProvider->supportsRegion('cn'));
        self::assertFalse($customProvider->supportsRegion(null));
        self::assertFalse($customProvider->supportsRegion('US'));

        $defaults = IpGeoProviderPolicy::fromArray([]);
        self::assertNotNull($defaults->provider('pconline'));
        self::assertGreaterThanOrEqual(6, count($defaults->providers));
    }

    public function testProviderEndpointAndSelectorRejectInvalidRuntimeInputs(): void
    {
        $provider = new IpGeoProviderDefinition(
            id: 'runtime-provider',
            endpointTemplate: 'https://geo.example/lookup/{ip}',
            fieldMap: ['country_code' => 'country_code'],
        );

        try {
            $provider->endpointForIp('not-an-ip');
            self::fail('Expected invalid provider endpoint IP to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Cannot build provider endpoint for invalid IP address.', $exception->getMessage());
        }

        $selector = new IpGeoProviderSelector(new IpGeoProviderPolicy(enabled: true, providers: []));
        try {
            $selector->select('CN', '203.0.113.60');
            self::fail('Expected selector without configured providers to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('No IP geo provider is configured for region.', $exception->getMessage());
        }

        $slot = new \ReflectionMethod(IpGeoProviderSelector::class, 'slot');
        $slot->setAccessible(true);
        try {
            $slot->invoke($selector, 'CN', '203.0.113.60', 0);
            self::fail('Expected non-positive selector weight to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('IP geo provider weights must be positive.', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function assertInvalidProviderConfiguration(array $configuration, string $message): void
    {
        try {
            IpGeoProviderDefinition::fromArray($configuration);
            self::fail('Expected invalid provider configuration to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function assertInvalidPolicyConfiguration(array $configuration, string $message): void
    {
        try {
            IpGeoProviderPolicy::fromArray($configuration);
            self::fail('Expected invalid IP geo policy configuration to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
