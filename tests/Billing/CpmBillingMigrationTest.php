<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use PHPUnit\Framework\TestCase;

final class CpmBillingMigrationTest extends TestCase
{
    public function testMigrationDefinesDurableAccumulatorAndPerEventAuditFacts(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260711080000_create_cpm_billing_state.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table cpm_billing_accumulators',
            'unique key uq_cpm_billing_accumulators_stream (stream_key)',
            'gross_remainder_milli_points smallint unsigned not null default 0',
            'publisher_share_remainder_numerator int unsigned not null default 0',
            'constraint chk_cpm_accumulators_gross_remainder check (gross_remainder_milli_points < 1000)',
            'constraint chk_cpm_accumulators_publisher_remainder check (publisher_share_remainder_numerator < 10000000)',
            'constraint chk_cpm_accumulators_publisher_points check (publisher_points <= billed_points)',
            'create table cpm_billing_event_allocations',
            'unique key uq_cpm_billing_event_allocations_event (event_key)',
            "constraint chk_cpm_allocations_status check (status in ('processing', 'accrued', 'billed', 'skipped'))",
            'constraint chk_cpm_allocations_bid_positive check (bid_points_per_thousand > 0)',
            'platform_points bigint not null default 0',
            'constraint chk_cpm_allocations_publisher_remainder_before check (publisher_share_remainder_before < 10000000)',
            'constraint chk_cpm_allocations_publisher_remainder_after check (publisher_share_remainder_after < 10000000)',
            'constraint chk_cpm_allocations_points_reconcile check (gross_points = publisher_points + platform_points)',
            'constraint chk_cpm_allocations_assessed_points check (assessed_gross_points >= gross_points)',
            "constraint chk_cpm_allocations_lifecycle check ( (status = 'processing' and processed_at is null) or (status <> 'processing' and processed_at is not null) )",
            "constraint chk_cpm_allocations_processing check ( status <> 'processing'",
            "constraint chk_cpm_allocations_billed check ( status <> 'billed'",
            "constraint chk_cpm_allocations_skipped check ( status <> 'skipped'",
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }

        $accumulatorSql = explode('create table cpm_billing_event_allocations', $sql, 2)[0];
        self::assertStringNotContainsString('revenue_share_rule_id', $accumulatorSql);
        self::assertStringNotContainsString('share_ratio_bps int unsigned not null', $accumulatorSql);
    }

}
