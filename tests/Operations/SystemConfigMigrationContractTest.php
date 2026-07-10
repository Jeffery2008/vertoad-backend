<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use VertoAD\Install\BootstrapConfigCatalog;

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
        $catalog = BootstrapConfigCatalog::all();

        self::assertSame(['publisher_percent' => 70], $catalog['billing.default_revenue_share']);
        self::assertSame(['limit' => 60, 'window_seconds' => 60], $catalog['security.rate_limit']);
        self::assertSame(['seconds' => 604800], $catalog['attribution.default_window_seconds']);
        self::assertSame([
            'min_visible_ratio' => 0.5,
            'min_visible_ms' => 1000,
            'repeat_click_window_seconds' => 30,
        ], $catalog['serving.event_validation']);

        $review = $catalog['review.ai_policy'];
        self::assertSame('openai_compatible', $review['provider']);
        self::assertSame(12000, $review['max_input_tokens']);
        self::assertArrayNotHasKey('api_key', $review);

        $assets = $catalog['assets.upload_policy'];
        self::assertSame(900, $assets['upload_intent_ttl_seconds']);
        self::assertSame(['html', 'htm', 'js', 'mjs', 'svg'], $assets['blocked_extensions']);
        self::assertArrayHasKey('image/png', $assets['types']['image']['magic_signatures']);
        self::assertArrayHasKey('video/mp4', $assets['types']['video']['magic_signatures']);
        self::assertSame('<script', $assets['types']['text']['magic_signatures']['text/plain'][0]['forbid_ascii_ci']);

        self::assertSame([
            'batch_size' => 50,
            'http_timeout_seconds' => 5,
            'max_retry_count' => 3,
            'retry_base_backoff_seconds' => 300,
        ], $catalog['webhook.delivery_policy']);

        $turnstile = $catalog['security.turnstile_policy'];
        self::assertSame(5, $turnstile['timeout_seconds']);
        self::assertArrayNotHasKey('verify_url', $turnstile);
        self::assertContains('POST:/api/v1/auth/login', $turnstile['protected_endpoints']);
        self::assertNotContains('GET:/api/v1/oauth/authorize', $turnstile['protected_endpoints']);
        self::assertContains('POST:/api/v1/ads/track', $turnstile['conditional_protected_endpoints']);
        self::assertContains('GET:/api/v1/ads/click', $turnstile['conditional_protected_endpoints']);
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
