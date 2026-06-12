<?php

declare(strict_types=1);

namespace VertoAD\Service;

final readonly class RuntimeConfigHealthCheck
{
    private \Closure $check;

    public function __construct(callable $check)
    {
        $this->check = \Closure::fromCallable($check);
    }

    public static function fromSystemConfig(SystemConfigService $configs, ?callable $aiReviewApiKeyResolver = null): self
    {
        return new self(static function () use ($configs, $aiReviewApiKeyResolver): void {
            $configs->assetUploadPolicy();
            $configs->attributionDefaultWindowSeconds();
            $configs->rateLimitPolicy();
            $configs->servingEventPolicy();
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
        });
    }

    public function assertHealthy(): void
    {
        ($this->check)();
    }
}
