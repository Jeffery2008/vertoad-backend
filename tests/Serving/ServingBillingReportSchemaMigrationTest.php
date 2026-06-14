<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;

final class ServingBillingReportSchemaMigrationTest extends TestCase
{
    private string $servingSql;
    private string $aggregateSql;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $servingPath = $root . '/db/migrations/20260608120000_create_serving_persistence_tables.php';
        $aggregatePath = $root . '/db/migrations/20260609120000_create_report_aggregate_tables.php';

        self::assertFileExists($servingPath);
        self::assertFileExists($aggregatePath);
        self::assertFileDoesNotExist($root . '/db/migrations/20260613150000_harden_serving_billing_report_schema.php');

        $this->servingSql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($servingPath))) ?? '';
        $this->aggregateSql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($aggregatePath))) ?? '';
    }

    public function testServingPersistenceBaselineIncludesBillingResultColumns(): void
    {
        foreach (['billing_status', 'billed_points', 'publisher_earning_points', 'billing_reason', 'billing_processed_at'] as $column) {
            self::assertStringContainsString($column, $this->servingSql);
        }

        self::assertStringContainsString("billing_status varchar(32) not null default 'pending'", $this->servingSql);
        self::assertStringContainsString('billed_points bigint unsigned not null default 0', $this->servingSql);
        self::assertStringContainsString('publisher_earning_points bigint unsigned not null default 0', $this->servingSql);
        self::assertStringContainsString('key idx_ad_serving_events_billing_report (billing_status, event_type, occurred_at)', $this->servingSql);
        self::assertStringContainsString("constraint chk_ad_serving_events_billing_status check (billing_status in ('pending', 'billed', 'skipped', 'failed'))", $this->servingSql);
    }

    public function testReportAggregateBaselineUsesDeterministicDimensionKey(): void
    {
        foreach ([
            'dimension_key char(64) not null',
            "organization_role varchar(16) not null default 'platform'",
            'unique key uq_report_aggregates_bucket (granularity, bucket_start, dimension_key)',
            'key idx_report_aggregates_org_time (organization_id, organization_role, granularity, bucket_start)',
            'conversions bigint unsigned not null default 0',
            'conversion_value_points bigint unsigned not null default 0',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $this->aggregateSql);
        }
    }
}
