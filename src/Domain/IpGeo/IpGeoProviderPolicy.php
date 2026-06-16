<?php

declare(strict_types=1);

namespace VertoAD\Domain\IpGeo;

final readonly class IpGeoProviderPolicy
{
    /** @var array<string, IpGeoProviderDefinition> */
    private array $providersById;

    /**
     * @param list<IpGeoProviderDefinition> $providers
     */
    public function __construct(
        public bool $enabled = false,
        public array $providers = [],
        public int $batchSize = 100,
        public int $maxAttempts = 3,
        public int $retryBackoffSeconds = 300,
        public int $cacheTtlSeconds = 86400,
        public string $queueSource = 'serving',
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('IP geo batch_size must be positive.');
        }

        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('IP geo max_attempts must be positive.');
        }

        if ($retryBackoffSeconds < 1) {
            throw new \InvalidArgumentException('IP geo retry_backoff_seconds must be positive.');
        }

        if ($cacheTtlSeconds < 1) {
            throw new \InvalidArgumentException('IP geo cache_ttl_seconds must be positive.');
        }

        $providersById = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof IpGeoProviderDefinition) {
                throw new \InvalidArgumentException('IP geo providers must be provider definitions.');
            }
            if (isset($providersById[$provider->id])) {
                throw new \InvalidArgumentException('Duplicate IP geo provider id ' . $provider->id . '.');
            }
            $providersById[$provider->id] = $provider;
        }

        $this->providersById = $providersById;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $allowedKeys = array_fill_keys([
            'enabled',
            'providers',
            'include_builtins',
            'batch_size',
            'max_attempts',
            'retry_backoff_seconds',
            'cache_ttl_seconds',
            'queue_source',
        ], true);
        foreach ($value as $key => $_) {
            if (!isset($allowedKeys[(string) $key])) {
                throw new \InvalidArgumentException('IP geo provider policy contains unknown field ' . (string) $key . '.');
            }
        }

        $providers = [];
        foreach (self::providerValues($value) as $providerValue) {
            $providers[] = IpGeoProviderDefinition::fromArray($providerValue);
        }

        return new self(
            enabled: (bool) ($value['enabled'] ?? false),
            providers: $providers,
            batchSize: (int) ($value['batch_size'] ?? 100),
            maxAttempts: (int) ($value['max_attempts'] ?? 3),
            retryBackoffSeconds: (int) ($value['retry_backoff_seconds'] ?? 300),
            cacheTtlSeconds: (int) ($value['cache_ttl_seconds'] ?? 86400),
            queueSource: trim((string) ($value['queue_source'] ?? 'serving')) ?: 'serving',
        );
    }

    public static function default(): self
    {
        return new self(enabled: false, providers: self::builtInProviders());
    }

    /**
     * @return list<IpGeoProviderDefinition>
     */
    public static function builtInProviders(): array
    {
        return [
            new IpGeoProviderDefinition(
                id: 'ip-sb',
                endpointTemplate: 'https://api.ip.sb/geoip/{ip}',
                fieldMap: [
                    'country_code' => 'country_code',
                    'country_name' => 'country',
                    'region_code' => 'region_code',
                    'region_name' => 'region',
                    'city_name' => 'city',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'timezone' => 'timezone',
                ],
                regions: ['global'],
            ),
            new IpGeoProviderDefinition(
                id: 'ipapi-co',
                endpointTemplate: 'https://ipapi.co/{ip}/json/',
                fieldMap: [
                    'country_code' => 'country_code',
                    'country_name' => 'country_name',
                    'region_code' => 'region_code',
                    'region_name' => 'region',
                    'city_name' => 'city',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'timezone' => 'timezone',
                ],
                regions: ['global'],
            ),
            new IpGeoProviderDefinition(
                id: 'freeipapi',
                endpointTemplate: 'https://freeipapi.com/api/json/{ip}',
                fieldMap: [
                    'country_code' => 'countryCode',
                    'country_name' => 'countryName',
                    'region_name' => 'regionName',
                    'city_name' => 'cityName',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'timezone' => 'timeZone',
                ],
                regions: ['global'],
            ),
            new IpGeoProviderDefinition(
                id: 'ipwhois',
                endpointTemplate: 'https://ipwhois.app/json/{ip}?format=json',
                fieldMap: [
                    'country_code' => 'country_code',
                    'country_name' => 'country',
                    'region_name' => 'region',
                    'city_name' => 'city',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'timezone' => 'timezone',
                ],
                regions: ['global'],
            ),
            new IpGeoProviderDefinition(
                id: 'geojs',
                endpointTemplate: 'https://get.geojs.io/v1/ip/geo/{ip}.json',
                fieldMap: [
                    'country_code' => 'country_code',
                    'country_name' => 'country',
                    'region_name' => 'region',
                    'city_name' => 'city',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'timezone' => 'timezone',
                ],
                regions: ['global'],
            ),
            new IpGeoProviderDefinition(
                id: 'pconline',
                endpointTemplate: 'https://whois.pconline.com.cn/ipJson.jsp?ip={ip}&json=true',
                fieldMap: [
                    'country_code' => 'countryCode',
                    'region_code' => 'proCode',
                    'region_name' => 'pro',
                    'city_name' => 'city',
                ],
                regions: ['CN'],
            ),
        ];
    }

    public function provider(string $id): ?IpGeoProviderDefinition
    {
        return $this->providersById[$id] ?? null;
    }

    /**
     * @return list<IpGeoProviderDefinition>
     */
    public function providersForRegion(?string $region): array
    {
        $region = $region === null || trim($region) === '' ? 'global' : strtoupper(trim($region));
        $matches = array_values(array_filter(
            $this->providers,
            static fn (IpGeoProviderDefinition $provider): bool => in_array($region, array_map('strtoupper', $provider->regions), true),
        ));
        if ($matches !== []) {
            return $matches;
        }

        return array_values(array_filter(
            $this->providers,
            static fn (IpGeoProviderDefinition $provider): bool => in_array('GLOBAL', array_map('strtoupper', $provider->regions), true),
        ));
    }

    /**
     * @param array<string, mixed> $value
     * @return list<array<string, mixed>>
     */
    private static function providerValues(array $value): array
    {
        $providers = [];
        $configured = $value['providers'] ?? null;
        if ($configured === null) {
            if (($value['include_builtins'] ?? true) !== false) {
                foreach (self::builtInProviders() as $provider) {
                    $providers[] = [
                        'id' => $provider->id,
                        'endpoint_template' => $provider->endpointTemplate,
                        'regions' => $provider->regions,
                        'weight' => $provider->weight,
                        'timeout_seconds' => $provider->timeoutSeconds,
                        'fields' => $provider->fieldMap,
                        'headers' => $provider->headers,
                    ];
                }
            }

            return $providers;
        }

        if (($value['include_builtins'] ?? false) === true) {
            foreach (self::builtInProviders() as $provider) {
                $providers[] = [
                    'id' => $provider->id,
                    'endpoint_template' => $provider->endpointTemplate,
                    'regions' => $provider->regions,
                    'weight' => $provider->weight,
                    'timeout_seconds' => $provider->timeoutSeconds,
                    'fields' => $provider->fieldMap,
                    'headers' => $provider->headers,
                ];
            }
        }

        if (!is_array($configured)) {
            throw new \InvalidArgumentException('IP geo providers must be a list.');
        }

        foreach ($configured as $provider) {
            if (!is_array($provider)) {
                throw new \InvalidArgumentException('IP geo provider must be an object.');
            }
            $providers[] = $provider;
        }

        return $providers;
    }
}
