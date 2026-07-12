<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCpmBillingState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE cpm_billing_accumulators (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stream_key CHAR(64) NOT NULL,
    advertiser_organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    publisher_organization_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    ad_slot_id BIGINT UNSIGNED NOT NULL,
    gross_remainder_milli_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    publisher_share_remainder_numerator INT UNSIGNED NOT NULL DEFAULT 0,
    impression_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    billed_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    publisher_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cpm_billing_accumulators_stream (stream_key),
    KEY idx_cpm_billing_accumulators_campaign (advertiser_organization_id, campaign_id),
    CONSTRAINT fk_cpm_accumulators_advertiser FOREIGN KEY (advertiser_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_accumulators_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_accumulators_publisher FOREIGN KEY (publisher_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_accumulators_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_accumulators_slot FOREIGN KEY (ad_slot_id) REFERENCES ad_slots (id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpm_accumulators_gross_remainder CHECK (gross_remainder_milli_points < 1000),
    CONSTRAINT chk_cpm_accumulators_publisher_remainder CHECK (publisher_share_remainder_numerator < 10000000),
    CONSTRAINT chk_cpm_accumulators_publisher_points CHECK (publisher_points <= billed_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Durable CPM gross and weighted publisher entitlement state.'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE cpm_billing_event_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key CHAR(64) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    event_id VARCHAR(160) NOT NULL,
    decision_id VARCHAR(160) NOT NULL,
    stream_key CHAR(64) NOT NULL,
    accumulator_id BIGINT UNSIGNED NULL,
    advertiser_organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    publisher_organization_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    ad_slot_id BIGINT UNSIGNED NOT NULL,
    revenue_share_rule_id BIGINT UNSIGNED NULL,
    revenue_share_rule_key VARCHAR(64) NOT NULL,
    share_ratio_bps INT UNSIGNED NOT NULL,
    bid_points_per_thousand BIGINT UNSIGNED NOT NULL,
    assessed_gross_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    reason VARCHAR(120) NULL,
    gross_remainder_before SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    gross_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    gross_remainder_after SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    publisher_share_remainder_before INT UNSIGNED NOT NULL DEFAULT 0,
    publisher_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    publisher_share_remainder_after INT UNSIGNED NOT NULL DEFAULT 0,
    platform_points BIGINT NOT NULL DEFAULT 0,
    advertiser_ledger_entry_id BIGINT UNSIGNED NULL,
    publisher_ledger_entry_id BIGINT UNSIGNED NULL,
    reservation_id VARCHAR(160) NULL,
    occurred_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cpm_billing_event_allocations_event (event_key),
    KEY idx_cpm_billing_event_allocations_event (event_type, event_id, decision_id),
    KEY idx_cpm_billing_event_allocations_stream (stream_key, occurred_at),
    KEY idx_cpm_billing_event_allocations_campaign (advertiser_organization_id, campaign_id, occurred_at),
    CONSTRAINT fk_cpm_allocations_accumulator FOREIGN KEY (accumulator_id) REFERENCES cpm_billing_accumulators (id) ON DELETE SET NULL,
    CONSTRAINT fk_cpm_allocations_advertiser FOREIGN KEY (advertiser_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_publisher FOREIGN KEY (publisher_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_slot FOREIGN KEY (ad_slot_id) REFERENCES ad_slots (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_rule FOREIGN KEY (revenue_share_rule_id) REFERENCES revenue_share_rules (id) ON DELETE SET NULL,
    CONSTRAINT fk_cpm_allocations_advertiser_ledger FOREIGN KEY (advertiser_ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpm_allocations_publisher_ledger FOREIGN KEY (publisher_ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CONSTRAINT chk_cpm_allocations_event_type CHECK (event_type = 'impression'),
    CONSTRAINT chk_cpm_allocations_ratio CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CONSTRAINT chk_cpm_allocations_bid_positive CHECK (bid_points_per_thousand > 0),
    CONSTRAINT chk_cpm_allocations_status CHECK (status IN ('processing', 'accrued', 'billed', 'skipped')),
    CONSTRAINT chk_cpm_allocations_gross_remainder_before CHECK (gross_remainder_before < 1000),
    CONSTRAINT chk_cpm_allocations_gross_remainder_after CHECK (gross_remainder_after < 1000),
    CONSTRAINT chk_cpm_allocations_publisher_remainder_before CHECK (publisher_share_remainder_before < 10000000),
    CONSTRAINT chk_cpm_allocations_publisher_remainder_after CHECK (publisher_share_remainder_after < 10000000),
    CONSTRAINT chk_cpm_allocations_points_reconcile CHECK (gross_points = publisher_points + platform_points),
    CONSTRAINT chk_cpm_allocations_assessed_points CHECK (assessed_gross_points >= gross_points),
    CONSTRAINT chk_cpm_allocations_lifecycle CHECK (
        (status = 'processing' AND processed_at IS NULL)
        OR (status <> 'processing' AND processed_at IS NOT NULL)
    ),
    CONSTRAINT chk_cpm_allocations_processing CHECK (
        status <> 'processing'
        OR (
            assessed_gross_points = 0
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    ),
    CONSTRAINT chk_cpm_allocations_accrued CHECK (
        status <> 'accrued'
        OR (
            assessed_gross_points = 0
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    ),
    CONSTRAINT chk_cpm_allocations_billed CHECK (
        status <> 'billed'
        OR (
            (gross_points > 0 OR publisher_points > 0)
            AND assessed_gross_points = gross_points
            AND reason IS NULL
            AND (
                (gross_points = 0 AND advertiser_ledger_entry_id IS NULL AND reservation_id IS NULL)
                OR (gross_points > 0 AND advertiser_ledger_entry_id IS NOT NULL AND reservation_id IS NOT NULL)
            )
            AND (
                (publisher_points = 0 AND publisher_ledger_entry_id IS NULL)
                OR (publisher_points > 0 AND publisher_ledger_entry_id IS NOT NULL)
            )
        )
    ),
    CONSTRAINT chk_cpm_allocations_skipped CHECK (
        status <> 'skipped'
        OR (
            reason IS NOT NULL
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND gross_remainder_before = gross_remainder_after
            AND publisher_share_remainder_before = publisher_share_remainder_after
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditable CPM per-event allocation and replay fact.'
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS cpm_billing_event_allocations');
        $this->execute('DROP TABLE IF EXISTS cpm_billing_accumulators');
    }
}
