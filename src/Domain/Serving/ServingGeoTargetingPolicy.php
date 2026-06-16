<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

final readonly class ServingGeoTargetingPolicy
{
    public const DEFAULT_PROVIDER = 'pconline';

    public bool $enabled;
    public string $provider;
    public string $endpoint;
    public int $timeoutSeconds;
    public int $cacheTtlSeconds;
    public string $cachePrefix;
    public string $defaultCountryCode;

    public function __construct(
        bool $enabled = false,
        string $provider = self::DEFAULT_PROVIDER,
        string $endpoint = 'https://whois.pconline.com.cn/ipJson.jsp',
        int $timeoutSeconds = 2,
        int $cacheTtlSeconds = 86400,
        string $cachePrefix = 'vertoad:geo:',
        string $defaultCountryCode = 'CN',
    ) {
        $provider = trim($provider);
        $endpoint = trim($endpoint);
        $cachePrefix = trim($cachePrefix);
        $defaultCountryCode = strtoupper(trim($defaultCountryCode));

        if ($provider === '') {
            throw new \InvalidArgumentException('serving.geo_provider provider is required.');
        }

        if ($endpoint === '') {
            throw new \InvalidArgumentException('serving.geo_provider endpoint is required.');
        }

        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('serving.geo_provider timeout_seconds must be positive.');
        }

        if ($cacheTtlSeconds < 1) {
            throw new \InvalidArgumentException('serving.geo_provider cache_ttl_seconds must be positive.');
        }

        if ($cachePrefix === '') {
            throw new \InvalidArgumentException('serving.geo_provider cache_prefix is required.');
        }

        if ($defaultCountryCode === '') {
            throw new \InvalidArgumentException('serving.geo_provider default_country_code is required.');
        }

        $this->enabled = $enabled;
        $this->provider = $provider;
        $this->endpoint = $endpoint;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->cacheTtlSeconds = $cacheTtlSeconds;
        $this->cachePrefix = $cachePrefix;
        $this->defaultCountryCode = $defaultCountryCode;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            enabled: (bool) ($value['enabled'] ?? false),
            provider: (string) ($value['provider'] ?? self::DEFAULT_PROVIDER),
            endpoint: (string) ($value['endpoint'] ?? 'https://whois.pconline.com.cn/ipJson.jsp'),
            timeoutSeconds: (int) ($value['timeout_seconds'] ?? 2),
            cacheTtlSeconds: (int) ($value['cache_ttl_seconds'] ?? 86400),
            cachePrefix: (string) ($value['cache_prefix'] ?? 'vertoad:geo:'),
            defaultCountryCode: (string) ($value['default_country_code'] ?? 'CN'),
        );
    }

    /**
     * @return array{enabled: bool, provider: string, endpoint: string, timeout_seconds: int, cache_ttl_seconds: int, cache_prefix: string, default_country_code: string}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'endpoint' => $this->endpoint,
            'timeout_seconds' => $this->timeoutSeconds,
            'cache_ttl_seconds' => $this->cacheTtlSeconds,
            'cache_prefix' => $this->cachePrefix,
            'default_country_code' => $this->defaultCountryCode,
        ];
    }
}
