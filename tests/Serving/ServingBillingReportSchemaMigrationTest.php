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

    public function testServingPersistenceBaselineIncludesRequestCorrelationColumns(): void
    {
        foreach ([
            'request_id varchar(160) null',
            'ip_address varchar(45) null',
            'user_agent varchar(512) null',
            'geo_code varchar(64) null',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $this->servingSql);
        }

        foreach ([
            'key idx_ad_serving_decisions_request_time (request_id, decided_at)',
            'key idx_ad_serving_decisions_ip_time (ip_address, decided_at)',
            'key idx_ad_serving_events_request_time (request_id, occurred_at)',
            'key idx_ad_serving_events_ip_time (ip_address, occurred_at)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $this->servingSql);
        }
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

    public function testServingEventsArePartitionedByOccurredAtForMySqlHotStorage(): void
    {
        $migrationSql = $this->partitionMigrationSql();

        foreach ([
            'alter table ad_serving_events',
            'drop foreign key fk_ad_serving_events_decision',
            'drop foreign key fk_ad_serving_events_site',
            'drop foreign key fk_ad_serving_events_slot',
            'drop foreign key fk_ad_serving_events_campaign',
            'drop foreign key fk_ad_serving_events_advertiser_org',
            'drop foreign key fk_ad_serving_events_publisher_org',
            'drop primary key',
            'add primary key (id, occurred_at)',
            'partition by range columns (occurred_at)',
            "partition p_before_2026 values less than ('2026-01-01 00:00:00')",
            "partition p202606 values less than ('2026-07-01 00:00:00')",
            'partition p_future values less than (maxvalue)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }

        self::assertStringNotContainsString('add constraint fk_ad_serving_events', $migrationSql);
    }

    public function testServingEventDedupTableKeepsGlobalEventIdentityOutsidePartitionedHotTable(): void
    {
        $migrationSql = preg_replace(
            '/\s+/',
            ' ',
            strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260608120000_create_serving_persistence_tables.php')),
        ) ?? '';

        foreach ([
            'create table ad_serving_event_dedup',
            'event_type varchar(32) not null',
            'event_id varchar(160) not null',
            'occurred_at datetime not null',
            'created_at datetime not null',
            'primary key (event_type, event_id)',
            'key idx_ad_serving_event_dedup_occurred_at (occurred_at)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }

        self::assertStringContainsString('dedup', $migrationSql);
    }

    private function partitionMigrationSql(): string
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260615160000_partition_event_tables.php';
        self::assertFileExists($path);

        return preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
    }
}
