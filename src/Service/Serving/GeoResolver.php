<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use Throwable;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final readonly class GeoResolver implements GeoResolverInterface
{
    public function __construct(
        private GeoProviderInterface $provider,
        private RedisClientInterface $cache,
        private string $cachePrefix,
        private int $cacheTtlSeconds,
    ) {
        if (trim($cachePrefix) === '') {
            throw new \InvalidArgumentException('Geo cache prefix is required.');
        }

        if ($cacheTtlSeconds < 1) {
            throw new \InvalidArgumentException('Geo cache TTL must be positive.');
        }
    }

    public function resolve(?string $ipAddress, ?string $userAgent = null): ?string
    {
        $ipAddress = $this->normalizeIp($ipAddress);
        if ($ipAddress === null) {
            return null;
        }

        $cacheKey = $this->cacheKey($ipAddress);
        try {
            $cached = $this->cache->get($cacheKey);
        } catch (Throwable) {
            return null;
        }

        if (is_string($cached) && trim($cached) !== '') {
            return trim($cached);
        }

        try {
            $geo = $this->provider->lookup($ipAddress, $userAgent);
        } catch (Throwable) {
            return null;
        }

        if ($geo === null || trim($geo) === '') {
            return null;
        }

        $geo = trim($geo);

        try {
            $this->cache->setEx($cacheKey, $geo, $this->cacheTtlSeconds);
        } catch (Throwable) {
        }

        return $geo;
    }

    public function contextForRequest(?string $ipAddress, ?string $userAgent = null, ?string $requestId = null): ServingRequestContext
    {
        $geoCode = $this->resolve($ipAddress, $userAgent);

        return new ServingRequestContext($this->normalizeIp($ipAddress), $userAgent, $geoCode, null, $requestId);
    }

    private function cacheKey(string $ipAddress): string
    {
        return $this->cachePrefix . 'ip:' . hash('sha256', $ipAddress);
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        if (!is_string($ipAddress)) {
            return null;
        }

        $ipAddress = trim($ipAddress);
        if ($ipAddress === '' || @inet_pton($ipAddress) === false) {
            return null;
        }

        return $ipAddress;
    }
}
