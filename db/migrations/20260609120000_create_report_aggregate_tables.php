<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateReportAggregateTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE report_aggregates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    granularity VARCHAR(12) NOT NULL,
    bucket_start DATETIME NOT NULL,
    dimension_key CHAR(64) NOT NULL,
    organization_role VARCHAR(16) NOT NULL DEFAULT 'platform',
    organization_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    geo VARCHAR(120) NULL,
    device VARCHAR(120) NULL,
    browser VARCHAR(120) NULL,
    resolution VARCHAR(120) NULL,
    risk_bucket VARCHAR(120) NULL,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    spend_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    revenue_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    conversion_value_points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refreshed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_aggregates_bucket (granularity, bucket_start, dimension_key),
    KEY idx_report_aggregates_org_time (organization_id, organization_role, granularity, bucket_start),
    KEY idx_report_aggregates_campaign_time (campaign_id, granularity, bucket_start),
    KEY idx_report_aggregates_site_slot_time (site_id, slot_id, granularity, bucket_start),
    CONSTRAINT fk_report_aggregates_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_report_aggregates_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_report_aggregates_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_report_aggregates_slot FOREIGN KEY (slot_id) REFERENCES ad_slots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS report_aggregates');
    }
}
