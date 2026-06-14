<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use PHPUnit\Framework\TestCase;

final class ConversionPathReportIndexMigrationTest extends TestCase
{
    private string $migrationSql;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260610140000_add_conversion_path_report_indexes.php';
        self::assertFileExists($path);
        $this->migrationSql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
    }

    public function testAttributionConversionIndexMatchesPathLookupPredicates(): void
    {
        self::assertStringContainsString(
            'idx_attribution_conversions_path_lookup (attributed, occurred_at, click_event_id, campaign_id)',
            $this->migrationSql,
        );
        self::assertStringNotContainsString('idx_attribution_conversions_path_scope (organization_id, attributed, occurred_at)', $this->migrationSql);
    }

    public function testServingEventIndexesMatchBatchedViewerTouchpointLookup(): void
    {
        self::assertStringContainsString(
            'idx_ad_serving_events_adv_path (advertiser_organization_id, viewer_id, event_type, valid, occurred_at)',
            $this->migrationSql,
        );
        self::assertStringContainsString(
            'idx_ad_serving_events_pub_path (publisher_organization_id, viewer_id, event_type, valid, occurred_at)',
            $this->migrationSql,
        );
    }
}
