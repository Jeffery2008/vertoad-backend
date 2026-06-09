<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateFraudRiskFeatureTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE fraud_risk_features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(24) NOT NULL,
    scope_id VARCHAR(160) NOT NULL,
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    site_id BIGINT UNSIGNED NULL,
    slot_id BIGINT UNSIGNED NULL,
    viewer_id VARCHAR(160) NULL,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    invalid_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ctr_per_mille BIGINT UNSIGNED NOT NULL DEFAULT 0,
    invalid_click_rate_per_mille BIGINT UNSIGNED NOT NULL DEFAULT 0,
    risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    risk_bucket VARCHAR(24) NOT NULL,
    reasons_json JSON NOT NULL,
    computed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fraud_risk_features_scope_window (scope_type, scope_id, window_start, window_end),
    KEY idx_fraud_risk_features_scope_latest (scope_type, scope_id, window_end),
    KEY idx_fraud_risk_features_bucket (risk_bucket, risk_score, window_end),
    KEY idx_fraud_risk_features_site_slot (site_id, slot_id, window_end),
    CONSTRAINT fk_fraud_risk_features_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_fraud_risk_features_slot FOREIGN KEY (slot_id) REFERENCES ad_slots (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS fraud_risk_features');
    }
}
