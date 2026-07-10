<?php

declare(strict_types=1);

namespace VertoAD\Install;

final class BootstrapConfigCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'billing.default_revenue_share' => [
                'publisher_percent' => 70,
            ],
            'security.rate_limit' => [
                'limit' => 60,
                'window_seconds' => 60,
            ],
            'attribution.default_window_seconds' => [
                'seconds' => 604800,
            ],
            'serving.event_validation' => [
                'min_visible_ratio' => 0.5,
                'min_visible_ms' => 1000,
                'repeat_click_window_seconds' => 30,
            ],
            'serving.geo_provider' => [
                'enabled' => true,
                'include_builtins' => true,
                'batch_size' => 100,
                'max_attempts' => 3,
                'retry_backoff_seconds' => 300,
                'cache_ttl_seconds' => 604800,
                'queue_source' => 'serving',
                'default_country_code' => null,
                'providers' => [],
            ],
            'webhook.delivery_policy' => [
                'batch_size' => 50,
                'http_timeout_seconds' => 5,
                'max_retry_count' => 3,
                'retry_base_backoff_seconds' => 300,
            ],
            'security.turnstile_policy' => [
                'enabled' => true,
                'timeout_seconds' => 5,
                'protected_endpoints' => [
                    'POST:/api/v1/auth/register',
                    'POST:/api/v1/auth/login',
                    'POST:/api/v1/auth/password-reset/request',
                    'POST:/api/v1/auth/password-reset/confirm',
                    'POST:/api/v1/billing/recharge-keys/redeem',
                    'POST:/api/v1/oauth/consent',
                ],
                'conditional_protected_endpoints' => [
                    'POST:/api/v1/ads/track',
                    'GET:/api/v1/ads/click',
                ],
            ],
            'review.ai_policy' => [
                'enabled' => true,
                'provider' => 'openai_compatible',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4.1-mini',
                'prompt' => 'Return strict JSON with risk_score, risk_labels, reasons, and recommendation for VertoAD creative policy review.',
                'timeout_seconds' => 60,
                'max_input_tokens' => 12000,
                'max_output_tokens' => 2000,
                'temperature' => 0.2,
            ],
            'assets.upload_policy' => [
                'upload_intent_ttl_seconds' => 900,
                'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
                'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
                'types' => [
                    'image' => [
                        'max_bytes' => 10485760,
                        'max_width' => 4096,
                        'max_height' => 4096,
                        'allowed_content_types' => [
                            'png' => 'image/png',
                            'jpg' => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'gif' => 'image/gif',
                            'webp' => 'image/webp',
                        ],
                        'magic_signatures' => [
                            'image/png' => [['prefix_base64' => 'iVBORw0KGgo=']],
                            'image/jpeg' => [['prefix_base64' => '/9j/']],
                            'image/gif' => [
                                ['prefix_ascii' => 'GIF87a'],
                                ['prefix_ascii' => 'GIF89a'],
                            ],
                            'image/webp' => [['prefix_ascii' => 'RIFF', 'offset_ascii' => ['offset' => 8, 'value' => 'WEBP']]],
                        ],
                    ],
                    'video' => [
                        'max_bytes' => 209715200,
                        'max_width' => 3840,
                        'max_height' => 2160,
                        'max_duration_seconds' => 120.0,
                        'allowed_content_types' => [
                            'mp4' => 'video/mp4',
                            'webm' => 'video/webm',
                        ],
                        'magic_signatures' => [
                            'video/mp4' => [['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']]],
                            'video/webm' => [['prefix_base64' => 'GkXfow==']],
                        ],
                    ],
                    'fabric_snapshot' => [
                        'max_bytes' => 1048576,
                        'allowed_content_types' => ['json' => 'application/json'],
                        'magic_signatures' => [
                            'application/json' => [
                                ['trimmed_prefix_ascii' => '{'],
                                ['trimmed_prefix_ascii' => '['],
                            ],
                        ],
                    ],
                    'text' => [
                        'max_bytes' => 1048576,
                        'allowed_content_types' => ['txt' => 'text/plain'],
                        'magic_signatures' => [
                            'text/plain' => [['forbid_ascii_ci' => '<script']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
