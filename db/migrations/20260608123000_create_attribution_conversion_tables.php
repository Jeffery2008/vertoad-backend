<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAttributionConversionTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE attribution_conversions (
    event_id VARCHAR(255) NOT NULL,
    conversion_id VARCHAR(160) NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    oauth_client_id BIGINT UNSIGNED NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    attributed TINYINT(1) NOT NULL,
    click_event_id VARCHAR(160) NULL,
    decision_id VARCHAR(160) NULL,
    campaign_id BIGINT UNSIGNED NULL,
    window_seconds INT UNSIGNED NOT NULL,
    source VARCHAR(64) NOT NULL,
    conversion_name VARCHAR(160) NOT NULL,
    value_points BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_attribution_conversions_conversion_id (conversion_id),
    KEY idx_attribution_conversions_organization_created (organization_id, created_at),
    KEY idx_attribution_conversions_oauth_client_created (oauth_client_id, created_at),
    KEY idx_attribution_conversions_click_event (click_event_id),
    KEY idx_attribution_conversions_decision (decision_id),
    KEY idx_attribution_conversions_campaign_created (campaign_id, created_at),
    KEY idx_attribution_conversions_report (attributed, occurred_at, campaign_id),
    CONSTRAINT fk_attribution_conversions_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_attribution_conversions_oauth_client FOREIGN KEY (oauth_client_id) REFERENCES oauth_clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_attribution_conversions_recorded_by_user FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_attribution_conversions_decision FOREIGN KEY (decision_id) REFERENCES ad_serving_decisions (decision_id) ON DELETE SET NULL,
    CONSTRAINT fk_attribution_conversions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE ad_serving_events
    ADD KEY idx_ad_serving_events_attribution_click (viewer_id, event_type, valid, occurred_at, id)
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE ad_serving_events DROP KEY idx_ad_serving_events_attribution_click');
        $this->execute('DROP TABLE IF EXISTS attribution_conversions');
    }
}
