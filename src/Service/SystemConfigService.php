<?php

declare(strict_types=1);

namespace VertoAD\Service;

use UnexpectedValueException;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\Review\AiReviewPolicy;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Domain\Webhooks\WebhookDeliveryPolicy;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Repository\SystemConfigRepositoryInterface;

final class SystemConfigService
{
    private const DEFAULT_REVENUE_SHARE_KEY = 'billing.default_revenue_share';
    private const ATTRIBUTION_DEFAULT_WINDOW_KEY = 'attribution.default_window_seconds';
    private const RATE_LIMIT_KEY = 'security.rate_limit';
    private const SERVING_EVENT_VALIDATION_KEY = 'serving.event_validation';
    private const ASSET_UPLOAD_POLICY_KEY = 'assets.upload_policy';
    private const AI_REVIEW_POLICY_KEY = 'review.ai_policy';
    private const WEBHOOK_DELIVERY_POLICY_KEY = 'webhook.delivery_policy';
    private const TURNSTILE_POLICY_KEY = 'security.turnstile_policy';
    private const FALLBACK_PUBLISHER_PERCENT = 70;
    private const FALLBACK_ATTRIBUTION_DEFAULT_WINDOW_SECONDS = 604800;
    private const FALLBACK_RATE_LIMIT_LIMIT = 60;
    private const FALLBACK_RATE_LIMIT_WINDOW_SECONDS = 60;
    private const FALLBACK_SERVING_MIN_VISIBLE_RATIO = 0.5;
    private const FALLBACK_SERVING_MIN_VISIBLE_MS = 1000;
    private const FALLBACK_SERVING_REPEAT_CLICK_WINDOW_SECONDS = 30;

    public function __construct(
        private readonly SystemConfigRepositoryInterface $repository,
        private readonly bool $allowRuntimeFallbacks = true,
    ) {
    }

    public function defaultPublisherRevenueSharePercent(): int
    {
        $config = $this->findLatestValue(self::DEFAULT_REVENUE_SHARE_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: billing.default_revenue_share.');
            }

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

    public function servingEventPolicy(): ServingEventPolicy
    {
        $config = $this->findLatestValue(self::SERVING_EVENT_VALIDATION_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: serving.event_validation.');
            }

            return new ServingEventPolicy(
                self::FALLBACK_SERVING_MIN_VISIBLE_RATIO,
                self::FALLBACK_SERVING_MIN_VISIBLE_MS,
                self::FALLBACK_SERVING_REPEAT_CLICK_WINDOW_SECONDS,
            );
        }

        $minVisibleRatio = $config['min_visible_ratio'] ?? null;
        if ((!is_float($minVisibleRatio) && !is_int($minVisibleRatio)) || $minVisibleRatio < 0.0 || $minVisibleRatio > 1.0) {
            throw new UnexpectedValueException('Invalid serving.event_validation min_visible_ratio.');
        }

        $minVisibleMs = $config['min_visible_ms'] ?? null;
        if (!is_int($minVisibleMs) || $minVisibleMs < 1) {
            throw new UnexpectedValueException('Invalid serving.event_validation min_visible_ms.');
        }

        $repeatClickWindowSeconds = $config['repeat_click_window_seconds'] ?? null;
        if (!is_int($repeatClickWindowSeconds) || $repeatClickWindowSeconds < 1) {
            throw new UnexpectedValueException('Invalid serving.event_validation repeat_click_window_seconds.');
        }

        return new ServingEventPolicy((float) $minVisibleRatio, $minVisibleMs, $repeatClickWindowSeconds);
    }

    public function assetUploadPolicy(): AssetUploadPolicy
    {
        $config = $this->findLatestValue(self::ASSET_UPLOAD_POLICY_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: assets.upload_policy.');
            }

            return AssetUploadPolicy::default();
        }

        try {
            return AssetUploadPolicy::fromArray($config);
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid assets.upload_policy ' . $exception->getMessage(), previous: $exception);
        }
    }

    public function aiReviewPolicy(): AiReviewPolicy
    {
        $config = $this->findLatestValue(self::AI_REVIEW_POLICY_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: review.ai_policy.');
            }

            return AiReviewPolicy::disabledFallback();
        }

        try {
            return AiReviewPolicy::fromArray($config);
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid review.ai_policy ' . $exception->getMessage(), previous: $exception);
        }
    }

    public function webhookDeliveryPolicy(): WebhookDeliveryPolicy
    {
        $config = $this->findLatestValue(self::WEBHOOK_DELIVERY_POLICY_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: webhook.delivery_policy.');
            }

            return WebhookDeliveryPolicy::default();
        }

        try {
            return WebhookDeliveryPolicy::fromArray($config);
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid webhook.delivery_policy ' . $exception->getMessage(), previous: $exception);
        }
    }

    public function turnstilePolicy(): TurnstilePolicy
    {
        $config = $this->findLatestValue(self::TURNSTILE_POLICY_KEY);

        if ($config === null) {
            if (!$this->allowRuntimeFallbacks) {
                throw new \RuntimeException('Missing required system config: security.turnstile_policy.');
            }

            return TurnstilePolicy::default();
        }

        try {
            return TurnstilePolicy::fromArray($config);
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid security.turnstile_policy ' . $exception->getMessage(), previous: $exception);
        }
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
