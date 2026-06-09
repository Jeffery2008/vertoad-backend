<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'VertoAD API',
        'env' => getenv('APP_ENV') ?: 'local',
        'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
        'key' => getenv('APP_KEY') ?: '',
    ],
    'database' => [
        'driver' => getenv('DB_DRIVER') ?: 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'vertoad',
        'username' => getenv('DB_USERNAME') ?: 'vertoad',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],
    'redis' => [
        'driver' => getenv('REDIS_DRIVER') ?: 'auto',
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: '',
        'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
        'prefix' => getenv('REDIS_PREFIX') ?: 'vertoad:',
        'timeout_seconds' => (float) (getenv('REDIS_TIMEOUT_SECONDS') ?: 2.0),
        'read_timeout_seconds' => (float) (getenv('REDIS_READ_TIMEOUT_SECONDS') ?: 2.0),
        'serving_event_visibility_timeout_seconds' => (int) (getenv('REDIS_SERVING_EVENT_VISIBILITY_TIMEOUT_SECONDS') ?: 300),
        'serving_event_retention_seconds' => (int) (getenv('REDIS_SERVING_EVENT_RETENTION_SECONDS') ?: 604800),
    ],
    'operations' => [
        'redis_hardening_inventory' => [
            'dangerous_commands_disabled' => array_values(array_filter(array_map(
                static fn (string $command): string => strtoupper(trim($command)),
                explode(',', getenv('REDIS_DANGEROUS_COMMANDS_DISABLED') ?: '')
            ))),
            'auth_failure_alerting_configured' => filter_var(
                getenv('REDIS_AUTH_FAILURE_ALERTING_CONFIGURED') ?: false,
                FILTER_VALIDATE_BOOL
            ),
        ],
    ],
    'storage' => [
        's3' => [
            'endpoint' => getenv('S3_ENDPOINT') ?: '',
            'region' => getenv('S3_REGION') ?: 'auto',
            'bucket' => getenv('S3_BUCKET') ?: 'vertoad',
            'access_key_id' => getenv('S3_ACCESS_KEY_ID') ?: '',
            'secret_access_key' => getenv('S3_SECRET_ACCESS_KEY') ?: '',
            'path_style_endpoint' => filter_var(getenv('S3_PATH_STYLE_ENDPOINT') ?: true, FILTER_VALIDATE_BOOL),
            'public_base_url' => getenv('R2_PUBLIC_BASE_URL') ?: '',
        ],
    ],
    'assets' => [
        'upload_intent_ttl_seconds' => (int) (getenv('ASSET_UPLOAD_INTENT_TTL_SECONDS') ?: 900),
        'image_max_bytes' => (int) (getenv('ASSET_IMAGE_MAX_BYTES') ?: 10485760),
        'video_max_bytes' => (int) (getenv('ASSET_VIDEO_MAX_BYTES') ?: 209715200),
        'snapshot_max_bytes' => (int) (getenv('ASSET_SNAPSHOT_MAX_BYTES') ?: 1048576),
        'image_max_width' => (int) (getenv('ASSET_IMAGE_MAX_WIDTH') ?: 4096),
        'image_max_height' => (int) (getenv('ASSET_IMAGE_MAX_HEIGHT') ?: 4096),
        'video_max_width' => (int) (getenv('ASSET_VIDEO_MAX_WIDTH') ?: 3840),
        'video_max_height' => (int) (getenv('ASSET_VIDEO_MAX_HEIGHT') ?: 2160),
        'video_max_duration_seconds' => (float) (getenv('ASSET_VIDEO_MAX_DURATION_SECONDS') ?: 120),
    ],
    'oauth' => [
        'private_key_path' => getenv('OAUTH_PRIVATE_KEY_PATH') ?: 'storage/oauth/private.key',
        'public_key_path' => getenv('OAUTH_PUBLIC_KEY_PATH') ?: 'storage/oauth/public.key',
        'encryption_key' => getenv('OAUTH_ENCRYPTION_KEY') ?: '',
        'authorization_url' => getenv('OAUTH_AUTHORIZATION_URL') ?: '',
        'token_url' => getenv('OAUTH_TOKEN_URL') ?: '',
        'authorization_code_ttl_seconds' => (int) (getenv('OAUTH_AUTHORIZATION_CODE_TTL_SECONDS') ?: 300),
        'access_token_ttl_seconds' => (int) (getenv('OAUTH_ACCESS_TOKEN_TTL_SECONDS') ?: 900),
        'refresh_token_ttl_seconds' => (int) (getenv('OAUTH_REFRESH_TOKEN_TTL_SECONDS') ?: 2592000),
    ],
    'cron' => [
        'token' => getenv('CRON_API_TOKEN') ?: '',
        'allowed_ips' => array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', getenv('CRON_API_ALLOWED_IPS') ?: '127.0.0.1,::1')
        ))),
        'lock_ttl_seconds' => (int) (getenv('CRON_LOCK_TTL_SECONDS') ?: 300),
        'event_consume_batch_size' => (int) (getenv('CRON_EVENT_CONSUME_BATCH_SIZE') ?: 500),
        'jobs' => [
            'redis-events-consume',
            'aggregate-statistics',
            'archive-parquet',
            'duckdb-cold-query',
            'ai-review-queue',
            'fraud-feature-compute',
            'expired-token-cleanup',
            'config-cache-refresh',
            'backup-check',
            'webhook-retry',
        ],
    ],
    'webhooks' => [
        'signing_secret' => getenv('WEBHOOK_SIGNING_SECRET') ?: 'whsec_local_dev_secret',
        'retry_batch_size' => (int) (getenv('WEBHOOK_RETRY_BATCH_SIZE') ?: 50),
        'http_timeout_seconds' => (int) (getenv('WEBHOOK_HTTP_TIMEOUT_SECONDS') ?: 5),
    ],
    'cloudflare' => [
        'real_ip_header' => getenv('CLOUDFLARE_REAL_IP_HEADER') ?: 'CF-Connecting-IP',
        'trusted_proxies' => array_values(array_filter(array_map(
            static fn (string $cidr): string => trim($cidr),
            explode(',', getenv('CLOUDFLARE_TRUSTED_PROXIES') ?: '')
        ))),
    ],
    'turnstile' => [
        'site_key' => getenv('TURNSTILE_SITE_KEY') ?: '',
        'secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
        'verify_url' => getenv('TURNSTILE_VERIFY_URL') ?: 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],
    'security' => [
        'rate_limit' => [
            'limit' => (int) (getenv('SECURITY_RATE_LIMIT') ?: 60),
            'window_seconds' => (int) (getenv('SECURITY_RATE_LIMIT_WINDOW_SECONDS') ?: 60),
        ],
    ],
    'ai_review' => [
        'base_url' => getenv('AI_REVIEW_BASE_URL') ?: '',
        'api_key' => getenv('AI_REVIEW_API_KEY') ?: '',
        'model' => getenv('AI_REVIEW_MODEL') ?: '',
        'prompt' => getenv('AI_REVIEW_PROMPT') ?: '',
        'timeout_seconds' => (int) (getenv('AI_REVIEW_TIMEOUT_SECONDS') ?: 60),
        'max_input_tokens' => (int) (getenv('AI_REVIEW_MAX_INPUT_TOKENS') ?: 12000),
        'max_output_tokens' => (int) (getenv('AI_REVIEW_MAX_OUTPUT_TOKENS') ?: 2000),
        'temperature' => (float) (getenv('AI_REVIEW_TEMPERATURE') ?: 0.2),
    ],
];
