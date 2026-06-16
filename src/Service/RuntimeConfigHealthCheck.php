<?php

declare(strict_types=1);

namespace VertoAD\Service;

use VertoAD\Repository\Billing\RevenueShareRepository;

final readonly class RuntimeConfigHealthCheck
{
    private \Closure $check;

    public function __construct(callable $check)
    {
        $this->check = \Closure::fromCallable($check);
    }

    public static function fromSystemConfig(
        SystemConfigService $configs,
        ?RevenueShareRepository $revenueShares = null,
        ?callable $aiReviewApiKeyResolver = null,
        ?callable $turnstileSecretResolver = null,
    ): self
    {
        return new self(static function () use ($configs, $revenueShares, $aiReviewApiKeyResolver, $turnstileSecretResolver): void {
            $configs->assetUploadPolicy();
            $configs->attributionDefaultWindowSeconds();
            $defaultPublisherPercent = $configs->defaultPublisherRevenueSharePercent();
            if ($revenueShares !== null) {
                $globalRule = $revenueShares->findActiveGlobalRule();
                if ($globalRule === null) {
                    throw new \RuntimeException('Missing active global revenue share rule for billing.default_revenue_share.');
                }

                if ($globalRule->shareRatioBps !== $defaultPublisherPercent * 100) {
                    throw new \RuntimeException('Global revenue share rule must match billing.default_revenue_share publisher_percent.');
                }
            }
            $configs->rateLimitPolicy();
            $configs->servingEventPolicy();
            $configs->ipGeoProviderPolicy();
            $aiReviewPolicy = $configs->aiReviewPolicy();
            if ($aiReviewApiKeyResolver !== null) {
                if (!$aiReviewPolicy->enabled) {
                    throw new \RuntimeException('review.ai_policy must enable AI review outside local/testing.');
                }

                if (trim((string) $aiReviewApiKeyResolver()) === '') {
                    throw new \RuntimeException('AI_REVIEW_API_KEY is required outside local/testing.');
                }
            }
            $configs->webhookDeliveryPolicy();
            $turnstilePolicy = $configs->turnstilePolicy();
            if ($turnstileSecretResolver !== null) {
                if (!$turnstilePolicy->enabled) {
                    throw new \RuntimeException('security.turnstile_policy must be enabled outside local/testing.');
                }

                if (trim((string) $turnstileSecretResolver()) === '') {
                    throw new \RuntimeException('TURNSTILE_SECRET_KEY is required outside local/testing when security.turnstile_policy is enabled.');
                }
            }
        });
    }

    public function assertHealthy(): void
    {
        ($this->check)();
    }
}
