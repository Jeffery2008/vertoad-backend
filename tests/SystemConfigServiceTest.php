<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\Review\AiReviewPolicy;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Domain\Webhooks\WebhookDeliveryPolicy;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\SystemConfigService;

final class SystemConfigServiceTest extends TestCase
{
    public function testDefaultRevenueShareUsesConfiguredPublisherPercent(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                TestCase::assertSame('billing.default_revenue_share', $configKey);

                return ['publisher_percent' => 72];
            }

            public function listLatestValues(): array
            {
                return ['billing.default_revenue_share' => ['publisher_percent' => 72]];
            }
        };

        $service = new SystemConfigService($repository);

        self::assertSame(72, $service->defaultPublisherRevenueSharePercent());
    }

    public function testDefaultRevenueShareFallsBackWhenConfigIsMissing(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                return null;
            }

            public function listLatestValues(): array
            {
                return [];
            }
        };

        $service = new SystemConfigService($repository);

        self::assertSame(70, $service->defaultPublisherRevenueSharePercent());
    }

    public function testDefaultRevenueShareRejectsInvalidConfiguredPercent(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                return ['publisher_percent' => 101];
            }

            public function listLatestValues(): array
            {
                return ['billing.default_revenue_share' => ['publisher_percent' => 101]];
            }
        };

        $service = new SystemConfigService($repository);

        $this->expectException(\UnexpectedValueException::class);
        $service->defaultPublisherRevenueSharePercent();
    }

    public function testAttributionDefaultWindowUsesConfiguredSeconds(): void
    {
        $repository = new ArraySystemConfigRepository([
            'attribution.default_window_seconds' => ['seconds' => 7200],
        ]);

        $service = new SystemConfigService($repository);

        self::assertSame(7200, $service->attributionDefaultWindowSeconds());
        self::assertSame(['attribution.default_window_seconds'], $repository->queries);
    }

    public function testAttributionDefaultWindowFallsBackWhenConfigIsMissing(): void
    {
        $service = new SystemConfigService(new ArraySystemConfigRepository());

        self::assertSame(604800, $service->attributionDefaultWindowSeconds());
    }

    public function testAttributionDefaultWindowRejectsInvalidConfiguredSeconds(): void
    {
        foreach (
            [
                ['seconds' => 0],
                ['seconds' => -1],
                ['seconds' => '604800'],
                ['window_seconds' => 604800],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'attribution.default_window_seconds' => $value,
            ]));

            try {
                $service->attributionDefaultWindowSeconds();
                self::fail('Invalid attribution.default_window_seconds value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertSame('Invalid attribution.default_window_seconds seconds.', $exception->getMessage());
            }
        }
    }

    public function testRateLimitPolicyUsesConfiguredLimitAndWindow(): void
    {
        $repository = new ArraySystemConfigRepository([
            'security.rate_limit' => ['limit' => 120, 'window_seconds' => 45],
        ]);

        $policy = (new SystemConfigService($repository))->rateLimitPolicy();

        self::assertInstanceOf(RateLimitPolicy::class, $policy);
        self::assertSame(120, $policy->limit);
        self::assertSame(45, $policy->windowSeconds);
        self::assertSame(['security.rate_limit'], $repository->queries);
    }

    public function testRateLimitPolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->rateLimitPolicy();

        self::assertSame(60, $policy->limit);
        self::assertSame(60, $policy->windowSeconds);
    }

    public function testRateLimitPolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['limit' => 0, 'window_seconds' => 60],
                ['limit' => 60, 'window_seconds' => 0],
                ['limit' => '60', 'window_seconds' => 60],
                ['limit' => 60, 'window_seconds' => '60'],
                ['limit' => 60],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'security.rate_limit' => $value,
            ]));

            try {
                $service->rateLimitPolicy();
                self::fail('Invalid security.rate_limit value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid security.rate_limit ', $exception->getMessage());
            }
        }
    }

    public function testServingEventPolicyUsesConfiguredThresholds(): void
    {
        $repository = new ArraySystemConfigRepository([
            'serving.event_validation' => [
                'min_visible_ratio' => 0.75,
                'min_visible_ms' => 1500,
                'repeat_click_window_seconds' => 60,
            ],
        ]);

        $policy = (new SystemConfigService($repository))->servingEventPolicy();

        self::assertInstanceOf(ServingEventPolicy::class, $policy);
        self::assertSame(0.75, $policy->minVisibleRatio);
        self::assertSame(1500, $policy->minVisibleMs);
        self::assertSame(60, $policy->repeatClickWindowSeconds);
        self::assertSame(['serving.event_validation'], $repository->queries);
    }

    public function testServingEventPolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->servingEventPolicy();

        self::assertSame(0.5, $policy->minVisibleRatio);
        self::assertSame(1000, $policy->minVisibleMs);
        self::assertSame(30, $policy->repeatClickWindowSeconds);
    }

    public function testServingEventPolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['min_visible_ratio' => -0.1, 'min_visible_ms' => 1000, 'repeat_click_window_seconds' => 30],
                ['min_visible_ratio' => 1.1, 'min_visible_ms' => 1000, 'repeat_click_window_seconds' => 30],
                ['min_visible_ratio' => '0.5', 'min_visible_ms' => 1000, 'repeat_click_window_seconds' => 30],
                ['min_visible_ratio' => 0.5, 'min_visible_ms' => 0, 'repeat_click_window_seconds' => 30],
                ['min_visible_ratio' => 0.5, 'min_visible_ms' => 1000, 'repeat_click_window_seconds' => 0],
                ['min_visible_ratio' => 0.5, 'min_visible_ms' => 1000],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'serving.event_validation' => $value,
            ]));

            try {
                $service->servingEventPolicy();
                self::fail('Invalid serving.event_validation value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid serving.event_validation ', $exception->getMessage());
            }
        }
    }

    public function testAssetUploadPolicyUsesConfiguredRules(): void
    {
        $repository = new ArraySystemConfigRepository([
            'assets.upload_policy' => [
                'upload_intent_ttl_seconds' => 120,
                'blocked_extensions' => ['html'],
                'blocked_content_types' => ['text/html'],
                'types' => [
                    'image' => [
                        'max_bytes' => 2048,
                        'max_width' => 512,
                        'max_height' => 512,
                        'allowed_content_types' => ['avif' => 'image/avif'],
                        'magic_signatures' => [
                            'image/avif' => [
                                ['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']],
                            ],
                        ],
                    ],
                    'video' => [
                        'max_bytes' => 4096,
                        'max_width' => 640,
                        'max_height' => 360,
                        'max_duration_seconds' => 15.5,
                        'allowed_content_types' => ['webm' => 'video/webm'],
                        'magic_signatures' => [
                            'video/webm' => [
                                ['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")],
                            ],
                        ],
                    ],
                    'fabric_snapshot' => [
                        'max_bytes' => 1024,
                        'allowed_content_types' => ['json' => 'application/json'],
                        'magic_signatures' => [
                            'application/json' => [
                                ['trimmed_prefix_ascii' => '{'],
                            ],
                        ],
                    ],
                    'text' => [
                        'max_bytes' => 512,
                        'allowed_content_types' => ['txt' => 'text/plain'],
                        'magic_signatures' => [
                            'text/plain' => [
                                ['forbid_ascii_ci' => '<script'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $policy = (new SystemConfigService($repository))->assetUploadPolicy();

        self::assertInstanceOf(AssetUploadPolicy::class, $policy);
        self::assertSame(120, $policy->uploadIntentTtlSeconds);
        self::assertTrue($policy->isBlockedExtension('HTML'));
        self::assertTrue($policy->isBlockedContentType('text/html'));
        self::assertSame(['avif' => 'image/avif'], $policy->allowedContentTypes(AssetType::Image));
        self::assertSame(2048, $policy->maxBytes(AssetType::Image));
        self::assertSame(512, $policy->maxWidth(AssetType::Image));
        self::assertSame(512, $policy->maxHeight(AssetType::Image));
        self::assertSame(15.5, $policy->maxDurationSeconds(AssetType::Video));
        self::assertTrue($policy->matchesMagic('image/avif', "\x00\x00\x00\x18ftypavif"));
        self::assertSame(['assets.upload_policy'], $repository->queries);
    }

    public function testAssetUploadPolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->assetUploadPolicy();

        self::assertSame(900, $policy->uploadIntentTtlSeconds);
        self::assertSame('image/png', $policy->allowedContentTypes(AssetType::Image)['png']);
        self::assertSame(10_485_760, $policy->maxBytes(AssetType::Image));
        self::assertSame(4096, $policy->maxWidth(AssetType::Image));
        self::assertSame(4096, $policy->maxHeight(AssetType::Image));
        self::assertTrue($policy->matchesMagic('image/png', "\x89PNG\r\n\x1A\npayload"));
    }

    public function testAiReviewPolicyUsesConfiguredOpenAiCompatibleSettings(): void
    {
        $repository = new ArraySystemConfigRepository([
            'review.ai_policy' => [
                'enabled' => true,
                'provider' => 'openai_compatible',
                'base_url' => 'https://ai.example.test/v1/',
                'model' => 'review-model',
                'prompt' => 'Return strict JSON.',
                'timeout_seconds' => 12,
                'max_input_tokens' => 4096,
                'max_output_tokens' => 321,
                'temperature' => 0.4,
            ],
        ]);

        $policy = (new SystemConfigService($repository))->aiReviewPolicy();

        self::assertInstanceOf(AiReviewPolicy::class, $policy);
        self::assertTrue($policy->enabled);
        self::assertSame('openai_compatible', $policy->provider);
        self::assertSame('https://ai.example.test/v1', $policy->baseUrl);
        self::assertSame('review-model', $policy->model);
        self::assertSame('Return strict JSON.', $policy->prompt);
        self::assertSame(12, $policy->timeoutSeconds);
        self::assertSame(4096, $policy->maxInputTokens);
        self::assertSame(321, $policy->maxOutputTokens);
        self::assertSame(0.4, $policy->temperature);
        self::assertSame(['review.ai_policy'], $repository->queries);
    }

    public function testAiReviewPolicyFallsBackToDisabledLocalPolicyWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->aiReviewPolicy();

        self::assertFalse($policy->enabled);
        self::assertSame('openai_compatible', $policy->provider);
        self::assertSame('deterministic-v1', $policy->model);
    }

    public function testWebhookDeliveryPolicyUsesConfiguredRetrySettings(): void
    {
        $repository = new ArraySystemConfigRepository([
            'webhook.delivery_policy' => [
                'batch_size' => 17,
                'http_timeout_seconds' => 4,
                'max_retry_count' => 6,
                'retry_base_backoff_seconds' => 45,
            ],
        ]);

        $policy = (new SystemConfigService($repository))->webhookDeliveryPolicy();

        self::assertInstanceOf(WebhookDeliveryPolicy::class, $policy);
        self::assertSame(17, $policy->batchSize);
        self::assertSame(4, $policy->httpTimeoutSeconds);
        self::assertSame(6, $policy->maxRetryCount);
        self::assertSame(45, $policy->retryBaseBackoffSeconds);
        self::assertSame(['webhook.delivery_policy'], $repository->queries);
    }

    public function testWebhookDeliveryPolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->webhookDeliveryPolicy();

        self::assertSame(50, $policy->batchSize);
        self::assertSame(5, $policy->httpTimeoutSeconds);
        self::assertSame(3, $policy->maxRetryCount);
        self::assertSame(300, $policy->retryBaseBackoffSeconds);
    }

    public function testTurnstilePolicyUsesConfiguredTimeoutAndProtectedEndpoints(): void
    {
        $repository = new ArraySystemConfigRepository([
            'security.turnstile_policy' => [
                'enabled' => true,
                'timeout_seconds' => 4,
                'protected_endpoints' => [
                    'POST:/api/v1/auth/login',
                    'POST:/api/v1/oauth/consent',
                ],
            ],
        ]);

        $policy = (new SystemConfigService($repository))->turnstilePolicy();

        self::assertInstanceOf(TurnstilePolicy::class, $policy);
        self::assertTrue($policy->enabled);
        self::assertSame(4, $policy->timeoutSeconds);
        self::assertTrue($policy->protects('post', '/api/v1/auth/login'));
        self::assertFalse($policy->protects('POST', '/api/v1/oauth/token'));
        self::assertSame(['security.turnstile_policy'], $repository->queries);
    }

    public function testTurnstilePolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->turnstilePolicy();

        self::assertTrue($policy->enabled);
        self::assertSame(5, $policy->timeoutSeconds);
        self::assertTrue($policy->protects('POST', '/anything'));
    }

    public function testWebhookDeliveryPolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['batch_size' => 0, 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 0, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 0, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 0],
                ['batch_size' => 501, 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 61, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 21, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 86401],
                ['batch_size' => '50', 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 3],
                ['batch_size' => 50, 'http_timeout_seconds' => 5, 'max_retry_count' => 3, 'retry_base_backoff_seconds' => 300, 'unexpected' => true],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'webhook.delivery_policy' => $value,
            ]));

            try {
                $service->webhookDeliveryPolicy();
                self::fail('Invalid webhook.delivery_policy value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid webhook.delivery_policy ', $exception->getMessage());
            }
        }
    }

    public function testTurnstilePolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['timeout_seconds' => 5, 'protected_endpoints' => ['POST:/api/v1/auth/login']],
                ['enabled' => true, 'protected_endpoints' => ['POST:/api/v1/auth/login']],
                ['enabled' => true, 'timeout_seconds' => 5],
                ['enabled' => true, 'verify_url' => 'https://turnstile.example.test/siteverify', 'timeout_seconds' => 5, 'protected_endpoints' => ['POST:/api/v1/auth/login']],
                ['enabled' => true, 'timeout_seconds' => 0, 'protected_endpoints' => ['POST:/api/v1/auth/login']],
                ['enabled' => true, 'timeout_seconds' => 31, 'protected_endpoints' => ['POST:/api/v1/auth/login']],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => []],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => ['*']],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => ['POST:/api/v1/auth/login' => true]],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => [42]],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => ['GET:/api/v1/auth/login']],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => ['POST:relative']],
                ['enabled' => true, 'timeout_seconds' => 5, 'protected_endpoints' => ['POST:/api/v1/auth/login'], 'unexpected' => true],
                ['enabled' => true, 'timeout_seconds' => '5', 'protected_endpoints' => ['POST:/api/v1/auth/login']],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'security.turnstile_policy' => $value,
            ]));

            try {
                $service->turnstilePolicy();
                self::fail('Invalid security.turnstile_policy value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid security.turnstile_policy ', $exception->getMessage());
            }
        }
    }

    public function testAiReviewPolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'unknown', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'not-a-url', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => '', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => '', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 0, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 0, 'max_output_tokens' => 2000, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 0, 'temperature' => 0.2],
                ['enabled' => true, 'provider' => 'openai_compatible', 'base_url' => 'https://ai.example.test/v1', 'model' => 'review-model', 'prompt' => 'Return JSON.', 'timeout_seconds' => 60, 'max_input_tokens' => 12000, 'max_output_tokens' => 2000, 'temperature' => 2.1],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'review.ai_policy' => $value,
            ]));

            try {
                $service->aiReviewPolicy();
                self::fail('Invalid review.ai_policy value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid review.ai_policy ', $exception->getMessage());
            }
        }
    }

    public function testAssetUploadPolicyRejectsInvalidConfiguredValues(): void
    {
        $validType = [
            'image' => [
                'max_bytes' => 2048,
                'max_width' => 512,
                'max_height' => 512,
                'allowed_content_types' => ['png' => 'image/png'],
                'magic_signatures' => ['image/png' => [['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")]]],
            ],
            'video' => [
                'max_bytes' => 4096,
                'max_width' => 640,
                'max_height' => 360,
                'max_duration_seconds' => 15,
                'allowed_content_types' => ['webm' => 'video/webm'],
                'magic_signatures' => ['video/webm' => [['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")]]],
            ],
            'fabric_snapshot' => [
                'max_bytes' => 1024,
                'allowed_content_types' => ['json' => 'application/json'],
                'magic_signatures' => ['application/json' => [['trimmed_prefix_ascii' => '{']]],
            ],
            'text' => [
                'max_bytes' => 512,
                'allowed_content_types' => ['txt' => 'text/plain'],
                'magic_signatures' => ['text/plain' => [['forbid_ascii_ci' => '<script']]],
            ],
        ];

        foreach (
            [
                ['upload_intent_ttl_seconds' => 59, 'blocked_extensions' => [], 'blocked_content_types' => [], 'types' => $validType],
                ['upload_intent_ttl_seconds' => 60, 'blocked_extensions' => [''], 'blocked_content_types' => [], 'types' => $validType],
                ['upload_intent_ttl_seconds' => 60, 'blocked_extensions' => [], 'blocked_content_types' => ['text/html'], 'types' => []],
                ['upload_intent_ttl_seconds' => 60, 'blocked_extensions' => [], 'blocked_content_types' => [], 'types' => ['image' => $validType['image']]],
                ['upload_intent_ttl_seconds' => 60, 'blocked_extensions' => [], 'blocked_content_types' => [], 'types' => [
                    ...$validType,
                    'image' => [...$validType['image'], 'max_bytes' => 0],
                ]],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'assets.upload_policy' => $value,
            ]));

            try {
                $service->assetUploadPolicy();
                self::fail('Invalid assets.upload_policy value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid assets.upload_policy ', $exception->getMessage());
            }
        }
    }

    public function testAssetUploadPolicyRejectsInvalidNestedPolicyShapes(): void
    {
        $cases = [
            static function (array $value): array {
                unset($value['types']);

                return $value;
            },
            static function (array $value): array {
                $value['blocked_extensions'] = 'html';

                return $value;
            },
            static function (array $value): array {
                $value['types']['video']['max_duration_seconds'] = 0;

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['allowed_content_types'] = [];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['allowed_content_types'] = ['png' => ''];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures'] = 'not-an-object';

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures'] = [];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = ['not-an-object-rule'];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['prefix_base64' => []]];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['prefix_base64' => '%%']];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['prefix_ascii' => '']];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['trimmed_prefix_ascii' => '']];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['forbid_ascii_ci' => '']];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['offset_ascii' => 'not-an-object']];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [['offset_ascii' => ['offset' => -1, 'value' => 'PNG']]];

                return $value;
            },
            static function (array $value): array {
                $value['types']['image']['magic_signatures']['image/png'] = [[]];

                return $value;
            },
        ];

        foreach ($cases as $mutate) {
            $this->assertInvalidAssetUploadPolicy($mutate($this->validAssetUploadPolicyConfig()));
        }
    }

    public function testAssetUploadPolicyMagicMatchersRejectWrongBytes(): void
    {
        $policy = AssetUploadPolicy::default();

        self::assertFalse($policy->matchesMagic('image/png', 'not-png'));
        self::assertFalse($policy->matchesMagic('image/webp', 'RIFFxxxxWRNGpayload'));
        self::assertFalse($policy->matchesMagic('application/json', 'not-json'));
        self::assertFalse($policy->matchesMagic('text/plain', 'hello <SCRIPT>alert(1)</SCRIPT>'));
        self::assertFalse($policy->matchesMagic('application/octet-stream', 'anything'));
    }

    public function testMissingRuntimeConfigFailsWhenFallbacksAreDisabled(): void
    {
        $service = new SystemConfigService(new ArraySystemConfigRepository(), allowRuntimeFallbacks: false);

        try {
            $service->attributionDefaultWindowSeconds();
            self::fail('Production runtime config must not silently fall back when attribution config is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Missing required system config: attribution.default_window_seconds.',
                $exception->getMessage(),
            );
        }

        try {
            $service->rateLimitPolicy();
            self::fail('Production runtime config must not silently fall back when rate limit config is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: security.rate_limit.', $exception->getMessage());
        }

        try {
            $service->servingEventPolicy();
            self::fail('Production runtime config must not silently fall back when serving event config is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: serving.event_validation.', $exception->getMessage());
        }

        try {
            $service->assetUploadPolicy();
            self::fail('Production runtime config must not silently fall back when asset upload policy is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: assets.upload_policy.', $exception->getMessage());
        }

        try {
            $service->aiReviewPolicy();
            self::fail('Production runtime config must not silently fall back when AI review policy is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: review.ai_policy.', $exception->getMessage());
        }

        try {
            $service->webhookDeliveryPolicy();
            self::fail('Production runtime config must not silently fall back when webhook delivery policy is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: webhook.delivery_policy.', $exception->getMessage());
        }

        try {
            $service->turnstilePolicy();
            self::fail('Production runtime config must not silently fall back when Turnstile policy is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: security.turnstile_policy.', $exception->getMessage());
        }
    }

    public function testRepositoryFailuresAreRethrownWhenRuntimeFallbacksAreDisabled(): void
    {
        $service = new SystemConfigService(new FailingSystemConfigRepository(), allowRuntimeFallbacks: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config repository unavailable');

        $service->rateLimitPolicy();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertInvalidAssetUploadPolicy(array $value): void
    {
        $service = new SystemConfigService(new ArraySystemConfigRepository([
            'assets.upload_policy' => $value,
        ]));

        try {
            $service->assetUploadPolicy();
            self::fail('Invalid assets.upload_policy value must be rejected.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringStartsWith('Invalid assets.upload_policy ', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validAssetUploadPolicyConfig(): array
    {
        return [
            'upload_intent_ttl_seconds' => 120,
            'blocked_extensions' => ['html'],
            'blocked_content_types' => ['text/html'],
            'types' => [
                'image' => [
                    'max_bytes' => 2048,
                    'max_width' => 512,
                    'max_height' => 512,
                    'allowed_content_types' => ['png' => 'image/png'],
                    'magic_signatures' => [
                        'image/png' => [
                            ['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")],
                        ],
                    ],
                ],
                'video' => [
                    'max_bytes' => 4096,
                    'max_width' => 640,
                    'max_height' => 360,
                    'max_duration_seconds' => 15,
                    'allowed_content_types' => ['webm' => 'video/webm'],
                    'magic_signatures' => [
                        'video/webm' => [
                            ['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")],
                        ],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1024,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [
                            ['trimmed_prefix_ascii' => '{'],
                        ],
                    ],
                ],
                'text' => [
                    'max_bytes' => 512,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [
                            ['forbid_ascii_ci' => '<script'],
                        ],
                    ],
                ],
            ],
        ];
    }
}

final class ArraySystemConfigRepository implements SystemConfigRepositoryInterface
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, array<string, mixed>> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function findLatestValue(string $configKey): ?array
    {
        $this->queries[] = $configKey;

        return $this->values[$configKey] ?? null;
    }

    public function listLatestValues(): array
    {
        return $this->values;
    }
}

final class FailingSystemConfigRepository implements SystemConfigRepositoryInterface
{
    public function findLatestValue(string $configKey): ?array
    {
        throw new \RuntimeException('config repository unavailable');
    }

    public function listLatestValues(): array
    {
        throw new \RuntimeException('config repository unavailable');
    }
}
