<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class SystemConfigMigrationContractTest extends TestCase
{
    public function testSystemConfigVersionsSchemaIsCreatedOnceAndMatchesRepositories(): void
    {
        $migrationSql = $this->migrationSql();

        self::assertSame(
            1,
            preg_match_all('/CREATE TABLE system_config_versions\s*\(/i', $migrationSql),
            'system_config_versions must be created once so fresh Phinx migrations do not fail on duplicate tables.',
        );
        self::assertSame(
            1,
            preg_match_all('/DROP TABLE IF EXISTS system_config_versions/i', $migrationSql),
            'Only the core schema rollback should drop system_config_versions.',
        );
        self::assertMatchesRegularExpression(
            '/CREATE TABLE system_config_versions\s*\([\s\S]*version_id VARCHAR\(80\) NOT NULL[\s\S]*PRIMARY KEY \(version_id\)/i',
            $migrationSql,
        );
        self::assertMatchesRegularExpression(
            '/created_by_user_id BIGINT UNSIGNED NOT NULL/i',
            $migrationSql,
        );
        self::assertMatchesRegularExpression(
            '/KEY idx_system_config_versions_key_created \(config_key, created_at\)/i',
            $migrationSql,
        );
        self::assertStringNotContainsString(
            'CreateSystemConfigVersionTables',
            $migrationSql,
            'Pre-launch schema should not carry a second system_config_versions migration; core schema is the source of truth.',
        );
    }

    public function testBootstrapSeedsRequiredRuntimeBusinessConfigVersions(): void
    {
        $bootstrapSql = (string) file_get_contents(dirname(__DIR__, 2) . '/db/init-super-admin.sql');

        self::assertStringContainsString('INSERT INTO system_config_versions', $bootstrapSql);
        self::assertStringContainsString("'billing.default_revenue_share'", $bootstrapSql);
        self::assertStringContainsString("JSON_OBJECT('publisher_percent', 70)", $bootstrapSql);
        self::assertStringContainsString("'security.rate_limit'", $bootstrapSql);
        self::assertStringContainsString("JSON_OBJECT('limit', 60, 'window_seconds', 60)", $bootstrapSql);
        self::assertStringContainsString("'attribution.default_window_seconds'", $bootstrapSql);
        self::assertStringContainsString("JSON_OBJECT('seconds', 604800)", $bootstrapSql);
        self::assertStringContainsString("'serving.event_validation'", $bootstrapSql);
        self::assertStringContainsString("'min_visible_ratio', 0.5", $bootstrapSql);
        self::assertStringContainsString("'min_visible_ms', 1000", $bootstrapSql);
        self::assertStringContainsString("'repeat_click_window_seconds', 30", $bootstrapSql);
        self::assertStringContainsString("'review.ai_policy'", $bootstrapSql);
        self::assertStringContainsString("'provider', 'openai_compatible'", $bootstrapSql);
        self::assertStringContainsString("'max_input_tokens', 12000", $bootstrapSql);
        self::assertStringNotContainsString('AI_REVIEW_API_KEY', $bootstrapSql);
        self::assertStringContainsString("'assets.upload_policy'", $bootstrapSql);
        self::assertStringContainsString("'upload_intent_ttl_seconds', 900", $bootstrapSql);
        self::assertStringContainsString("'blocked_extensions', JSON_ARRAY('html', 'htm', 'js', 'mjs', 'svg')", $bootstrapSql);
        self::assertStringContainsString("'image/png', JSON_ARRAY(JSON_OBJECT('prefix_base64'", $bootstrapSql);
        self::assertStringContainsString("'video/mp4', JSON_ARRAY(JSON_OBJECT('offset_ascii'", $bootstrapSql);
        self::assertStringContainsString("'text/plain', JSON_ARRAY(JSON_OBJECT('forbid_ascii_ci', '<script'))", $bootstrapSql);
        self::assertStringContainsString("'webhook.delivery_policy'", $bootstrapSql);
        self::assertStringContainsString(
            "JSON_OBJECT('batch_size', 50, 'http_timeout_seconds', 5, 'max_retry_count', 3, 'retry_base_backoff_seconds', 300)",
            $bootstrapSql,
        );
        self::assertStringContainsString("'security.turnstile_policy'", $bootstrapSql);
        self::assertStringNotContainsString("'verify_url'", $bootstrapSql);
        self::assertStringContainsString("'timeout_seconds', 5", $bootstrapSql);
        self::assertStringContainsString("'protected_endpoints', JSON_ARRAY(", $bootstrapSql);
        self::assertStringContainsString("'POST:/api/v1/auth/login'", $bootstrapSql);
        self::assertStringContainsString("'GET:/api/v1/oauth/authorize'", $bootstrapSql);
        self::assertStringNotContainsString('TURNSTILE_SECRET_KEY', $bootstrapSql);
        self::assertStringContainsString('INSERT INTO revenue_share_rules', $bootstrapSql);
        self::assertStringContainsString("'global'", $bootstrapSql);
        self::assertStringContainsString('7000', $bootstrapSql);
        self::assertStringContainsString("'active'", $bootstrapSql);
        self::assertStringContainsString('@super_admin_user_id', $bootstrapSql);
    }

    private function migrationSql(): string
    {
        $migrationDir = dirname(__DIR__, 2) . '/db/migrations';
        $sql = '';

        foreach (glob($migrationDir . '/*.php') ?: [] as $path) {
            $sql .= "\n" . (string) file_get_contents($path);
        }

        return $sql;
    }
}
