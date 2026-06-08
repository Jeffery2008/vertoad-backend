<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServingPersistenceTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE ad_serving_decisions (
    decision_id VARCHAR(160) NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    viewer_id VARCHAR(160) NOT NULL,
    filled TINYINT(1) NOT NULL,
    reason VARCHAR(120) NULL,
    iframe_html MEDIUMTEXT NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    ad_id VARCHAR(160) NULL,
    campaign_id BIGINT UNSIGNED NULL,
    advertiser_organization_id BIGINT UNSIGNED NULL,
    publisher_organization_id BIGINT UNSIGNED NULL,
    impression_cost_points BIGINT UNSIGNED NULL,
    click_cost_points BIGINT UNSIGNED NULL,
    landing_url VARCHAR(2048) NULL,
    decided_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (decision_id),
    KEY idx_ad_serving_decisions_slot_time (site_id, slot_id, decided_at),
    KEY idx_ad_serving_decisions_viewer_time (viewer_id, decided_at),
    KEY idx_ad_serving_decisions_campaign_time (campaign_id, decided_at),
    CONSTRAINT fk_ad_serving_decisions_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_serving_decisions_slot FOREIGN KEY (slot_id) REFERENCES ad_slots (id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_serving_decisions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_ad_serving_decisions_advertiser_org FOREIGN KEY (advertiser_organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_ad_serving_decisions_publisher_org FOREIGN KEY (publisher_organization_id) REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ad_serving_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(32) NOT NULL,
    event_id VARCHAR(160) NOT NULL,
    decision_id VARCHAR(160) NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    viewer_id VARCHAR(160) NOT NULL,
    ad_id VARCHAR(160) NULL,
    campaign_id BIGINT UNSIGNED NULL,
    advertiser_organization_id BIGINT UNSIGNED NULL,
    publisher_organization_id BIGINT UNSIGNED NULL,
    cost_points BIGINT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL,
    valid TINYINT(1) NOT NULL,
    reason VARCHAR(120) NULL,
    visible_ratio DECIMAL(6, 5) NULL,
    visible_ms INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ad_serving_events_type_event (event_type, event_id),
    KEY idx_ad_serving_events_decision_viewer (decision_id, viewer_id, event_type, valid),
    KEY idx_ad_serving_events_processing (processed_at, occurred_at, id),
    KEY idx_ad_serving_events_report_advertiser (advertiser_organization_id, event_type, occurred_at),
    KEY idx_ad_serving_events_report_publisher (publisher_organization_id, event_type, occurred_at),
    KEY idx_ad_serving_events_campaign_time (campaign_id, event_type, occurred_at),
    CONSTRAINT fk_ad_serving_events_decision FOREIGN KEY (decision_id) REFERENCES ad_serving_decisions (decision_id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_serving_events_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_serving_events_slot FOREIGN KEY (slot_id) REFERENCES ad_slots (id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_serving_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_ad_serving_events_advertiser_org FOREIGN KEY (advertiser_organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_ad_serving_events_publisher_org FOREIGN KEY (publisher_organization_id) REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS ad_serving_events');
        $this->execute('DROP TABLE IF EXISTS ad_serving_decisions');
    }
}
