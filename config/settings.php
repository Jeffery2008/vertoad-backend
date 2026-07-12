<?php

declare(strict_types=1);

$ipGeoRepository = getenv('IP_GEO_REPOSITORY');
$projectRoot = dirname(__DIR__);
$resolveProjectPath = static function (string $path) use ($projectRoot): string {
    $path = trim($path);
    $absolute = str_starts_with($path, '/')
        || str_starts_with($path, '\\')
        || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1;
    if ($absolute) {
        return $path;
    }

    return $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
};

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'VertoAD API',
        'env' => getenv('APP_ENV') ?: 'local',
        'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
        'key' => getenv('APP_KEY') ?: '',
    ],
    'cors' => [
        'allowed_origins' => array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: (getenv('APP_URL') ?: ''))
        ))),
    ],
    'install' => [
        'token' => getenv('INSTALL_TOKEN') ?: '',
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
    'backup' => [
        's3' => [
            'endpoint' => getenv('BACKUP_S3_ENDPOINT') ?: '',
            'region' => getenv('BACKUP_S3_REGION') ?: 'auto',
            'bucket' => getenv('BACKUP_S3_BUCKET') ?: '',
            'access_key_id' => getenv('BACKUP_S3_ACCESS_KEY_ID') ?: '',
            'secret_access_key' => getenv('BACKUP_S3_SECRET_ACCESS_KEY') ?: '',
            'path_style_endpoint' => filter_var(
                getenv('BACKUP_S3_PATH_STYLE_ENDPOINT') ?: true,
                FILTER_VALIDATE_BOOL
            ),
        ],
        'base_object_key' => getenv('BACKUP_BASE_OBJECT_KEY') ?: 'backups',
        'temp_dir' => getenv('BACKUP_TEMP_DIR') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-backups',
        'mysql_dump_binary' => getenv('BACKUP_MYSQL_DUMP_BINARY') ?: 'mysqldump',
        'mysql_restore_binary' => getenv('BACKUP_MYSQL_RESTORE_BINARY') ?: 'mysql',
        'command_timeout_seconds' => (int) (getenv('BACKUP_COMMAND_TIMEOUT_SECONDS') ?: 900),
        'restore_allowed_environments' => array_values(array_filter(array_map(
            static fn (string $environment): string => strtolower(trim($environment)),
            explode(',', getenv('BACKUP_RESTORE_ALLOWED_ENVIRONMENTS') ?: 'staging')
        ))),
        'server_side_encryption' => getenv('BACKUP_S3_SERVER_SIDE_ENCRYPTION') ?: 'AES256',
    ],
    'ip_geo' => [
        'repository' => $ipGeoRepository === false ? null : $ipGeoRepository,
        'visibility_timeout_seconds' => (int) (getenv('IP_GEO_VISIBILITY_TIMEOUT_SECONDS') ?: 300),
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
            'max_read_bytes' => (int) (getenv('ASSET_S3_MAX_READ_BYTES') ?: 262_144_000),
            'snapshot_cache_control' => getenv('ASSET_S3_SNAPSHOT_CACHE_CONTROL') ?: 'public, max-age=31536000, immutable',
            'server_side_encryption' => getenv('ASSET_S3_SERVER_SIDE_ENCRYPTION') ?: '',
        ],
    ],
    'integration' => [
        'sdk_public_base_url' => getenv('SDK_PUBLIC_BASE_URL') ?: '',
        'ads_public_base_url' => getenv('ADS_PUBLIC_BASE_URL') ?: '',
    ],
    'withdrawal_proofs' => [
        's3' => [
            'endpoint' => getenv('WITHDRAWAL_PROOF_S3_ENDPOINT') ?: '',
            'region' => getenv('WITHDRAWAL_PROOF_S3_REGION') ?: 'auto',
            'bucket' => getenv('WITHDRAWAL_PROOF_S3_BUCKET') ?: '',
            'access_key_id' => getenv('WITHDRAWAL_PROOF_S3_ACCESS_KEY_ID') ?: '',
            'secret_access_key' => getenv('WITHDRAWAL_PROOF_S3_SECRET_ACCESS_KEY') ?: '',
            'path_style_endpoint' => filter_var(
                getenv('WITHDRAWAL_PROOF_S3_PATH_STYLE_ENDPOINT') ?: true,
                FILTER_VALIDATE_BOOL
            ),
            'server_side_encryption' => getenv('WITHDRAWAL_PROOF_S3_SERVER_SIDE_ENCRYPTION') ?: 'AES256',
            'max_inspect_bytes' => (int) (getenv('WITHDRAWAL_PROOF_S3_MAX_INSPECT_BYTES') ?: 10_485_760),
        ],
    ],
    'archive' => [
        'raw_events_base_object_key' => getenv('ARCHIVE_RAW_EVENTS_BASE_OBJECT_KEY') ?: 's3://vertoad-archive/raw-events',
        'query_results_base_object_key' => getenv('ARCHIVE_QUERY_RESULTS_BASE_OBJECT_KEY') ?: 's3://vertoad-archive/query-results',
        'writer' => getenv('ARCHIVE_WRITER') ?: '',
        'cold_query_runner' => getenv('ARCHIVE_COLD_QUERY_RUNNER') ?: '',
        'duckdb_binary' => getenv('ARCHIVE_DUCKDB_BINARY') ?: 'duckdb',
        'temp_dir' => getenv('ARCHIVE_TEMP_DIR') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-archive',
        'command_timeout_seconds' => (int) (getenv('ARCHIVE_COMMAND_TIMEOUT_SECONDS') ?: 120),
        'max_scanned_objects' => (int) (getenv('ARCHIVE_MAX_SCANNED_OBJECTS') ?: 500),
        'max_result_bytes' => (int) (getenv('ARCHIVE_MAX_RESULT_BYTES') ?: 10485760),
    ],
    'oauth' => [
        'private_key_path' => $resolveProjectPath(getenv('OAUTH_PRIVATE_KEY_PATH') ?: 'storage/oauth/private.key'),
        'public_key_path' => $resolveProjectPath(getenv('OAUTH_PUBLIC_KEY_PATH') ?: 'storage/oauth/public.key'),
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
        'aggregate_statistics_lookback_hours' => (int) (getenv('CRON_AGGREGATE_STATISTICS_LOOKBACK_HOURS') ?: 24),
        'fraud_feature_lookback_hours' => (int) (getenv('CRON_FRAUD_FEATURE_LOOKBACK_HOURS') ?: 24),
        'ai_review_batch_size' => (int) (getenv('CRON_AI_REVIEW_BATCH_SIZE') ?: 50),
        'config_cache_ttl_seconds' => (int) (getenv('CRON_CONFIG_CACHE_TTL_SECONDS') ?: 300),
        'expired_token_retention_seconds' => (int) (getenv('CRON_EXPIRED_TOKEN_RETENTION_SECONDS') ?: 86400),
        'asset_snapshot_batch_size' => (int) (getenv('CRON_ASSET_SNAPSHOT_BATCH_SIZE') ?: 25),
        'asset_snapshot_lease_seconds' => (int) (getenv('CRON_ASSET_SNAPSHOT_LEASE_SECONDS') ?: 300),
        'asset_snapshot_max_attempts' => (int) (getenv('CRON_ASSET_SNAPSHOT_MAX_ATTEMPTS') ?: 3),
        'asset_snapshot_retry_backoff_seconds' => (int) (getenv('CRON_ASSET_SNAPSHOT_RETRY_BACKOFF_SECONDS') ?: 300),
        'asset_snapshot_ffmpeg_binary' => getenv('CRON_ASSET_SNAPSHOT_FFMPEG_BINARY') ?: 'ffmpeg',
        'asset_snapshot_ffmpeg_timeout_seconds' => (int) (getenv('CRON_ASSET_SNAPSHOT_FFMPEG_TIMEOUT_SECONDS') ?: 30),
        'jobs' => [
            'redis-events-consume',
            'ip-geo-resolve',
            'asset-snapshot-generate',
            'aggregate-statistics',
            'archive-parquet',
            'duckdb-cold-query',
            'ai-review-queue',
            'fraud-feature-compute',
            'expired-token-cleanup',
            'config-cache-refresh',
            'backup-check',
            'backup-create',
            'backup-restore',
            'partition-maintenance',
            'webhook-retry',
        ],
        'partition_maintenance_lookahead_months' => (int) (getenv('CRON_PARTITION_MAINTENANCE_LOOKAHEAD_MONTHS') ?: 3),
    ],
    'webhooks' => [
        'signing_secret' => getenv('WEBHOOK_SIGNING_SECRET') ?: 'whsec_local_dev_secret',
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
    ],
    'security' => [
        'rate_limit' => [
            'limit' => (int) (getenv('SECURITY_RATE_LIMIT') ?: 60),
            'window_seconds' => (int) (getenv('SECURITY_RATE_LIMIT_WINDOW_SECONDS') ?: 60),
        ],
    ],
    'ai_review' => [
        'api_key' => getenv('AI_REVIEW_API_KEY') ?: '',
    ],
];
