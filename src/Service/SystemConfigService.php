<?php

declare(strict_types=1);

namespace VertoAD\Service;

use UnexpectedValueException;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Repository\SystemConfigRepositoryInterface;

final class SystemConfigService
{
    private const DEFAULT_REVENUE_SHARE_KEY = 'billing.default_revenue_share';
    private const ATTRIBUTION_DEFAULT_WINDOW_KEY = 'attribution.default_window_seconds';
    private const RATE_LIMIT_KEY = 'security.rate_limit';
    private const FALLBACK_PUBLISHER_PERCENT = 70;
    private const FALLBACK_ATTRIBUTION_DEFAULT_WINDOW_SECONDS = 604800;
    private const FALLBACK_RATE_LIMIT_LIMIT = 60;
    private const FALLBACK_RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly SystemConfigRepositoryInterface $repository,
        private readonly bool $allowRuntimeFallbacks = true,
    ) {
    }

    public function defaultPublisherRevenueSharePercent(): int
    {
        $config = $this->findLatestValue(self::DEFAULT_REVENUE_SHARE_KEY);

        if ($config === null) {
            return self::FALLBACK_PUBLISHER_PERCENT;
        }

        $publisherPercent = $config['publisher_percent'] ?? null;
        if (!is_int($publisherPercent) || $publisherPercent < 0 || $publisherPercent > 100) {
            throw new UnexpectedValueException('Invalid billing.default_revenue_share publisher_percent.');
        }

        return $publisherPercent;
    }

    public function attributionDefaultWindowSeconds(): int
    {
        $config = $this->findLatestValue(self::ATTRIBUTION_DEFAULT_WINDOW_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: attribution.default_window_seconds.');
            }

            return self::FALLBACK_ATTRIBUTION_DEFAULT_WINDOW_SECONDS;
        }

        $seconds = $config['seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 1) {
            throw new UnexpectedValueException('Invalid attribution.default_window_seconds seconds.');
        }

        return $seconds;
    }

    public function rateLimitPolicy(): RateLimitPolicy
    {
        $config = $this->findLatestValue(self::RATE_LIMIT_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: security.rate_limit.');
            }

            return new RateLimitPolicy(
                self::FALLBACK_RATE_LIMIT_LIMIT,
                self::FALLBACK_RATE_LIMIT_WINDOW_SECONDS,
            );
        }

        $limit = $config['limit'] ?? null;
        if (!is_int($limit) || $limit < 1) {
            throw new UnexpectedValueException('Invalid security.rate_limit limit.');
        }

        $windowSeconds = $config['window_seconds'] ?? null;
        if (!is_int($windowSeconds) || $windowSeconds < 1) {
            throw new UnexpectedValueException('Invalid security.rate_limit window_seconds.');
        }

        return new RateLimitPolicy($limit, $windowSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestValue(string $configKey): ?array
    {
        try {
            return $this->repository->findLatestValue($configKey);
        } catch (\Throwable $exception) {
            if ($this->allowRuntimeFallbacks) {
                return null;
            }

            throw $exception;
        }
    }
}
