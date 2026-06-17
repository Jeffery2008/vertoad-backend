<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOperationCorrelationLogTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE operation_system_logs (
    log_id VARCHAR(160) NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    level VARCHAR(32) NOT NULL,
    message VARCHAR(1024) NOT NULL,
    endpoint VARCHAR(255) NULL,
    http_method VARCHAR(16) NULL,
    ip_address VARCHAR(45) NULL,
    source VARCHAR(64) NOT NULL,
    redacted_context_json JSON NOT NULL,
    raw_context_json JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (log_id),
    KEY idx_operation_system_logs_request (request_id, occurred_at),
    KEY idx_operation_system_logs_endpoint (endpoint, http_method, occurred_at),
    KEY idx_operation_system_logs_ip (ip_address, occurred_at),
    KEY idx_operation_system_logs_source_level (source, level, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE operation_risk_decision_logs (
    decision_id VARCHAR(160) NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    action VARCHAR(120) NOT NULL,
    risk_score TINYINT UNSIGNED NULL,
    reason_codes_json JSON NOT NULL,
    subject_type VARCHAR(64) NULL,
    subject_id VARCHAR(160) NULL,
    ip_address VARCHAR(45) NULL,
    endpoint VARCHAR(255) NULL,
    http_method VARCHAR(16) NULL,
    user_agent VARCHAR(512) NULL,
    site_id BIGINT UNSIGNED NULL,
    slot_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    viewer_id VARCHAR(160) NULL,
    ad_decision_id VARCHAR(160) NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (decision_id),
    KEY idx_operation_risk_decision_logs_request (request_id, occurred_at),
    KEY idx_operation_risk_decision_logs_subject (subject_type, subject_id, occurred_at),
    KEY idx_operation_risk_decision_logs_endpoint (endpoint, http_method, occurred_at),
    KEY idx_operation_risk_decision_logs_ip (ip_address, occurred_at),
    KEY idx_operation_risk_decision_logs_slot_viewer (site_id, slot_id, viewer_id, occurred_at),
    KEY idx_operation_risk_decision_logs_ad_decision (ad_decision_id),
    CONSTRAINT fk_operation_risk_decision_ad_decision FOREIGN KEY (ad_decision_id) REFERENCES ad_serving_decisions (decision_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS operation_risk_decision_logs');
        $this->execute('DROP TABLE IF EXISTS operation_system_logs');
    }
}
