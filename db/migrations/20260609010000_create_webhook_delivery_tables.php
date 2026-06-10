<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWebhookDeliveryTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE webhook_deliveries (
    delivery_id VARCHAR(160) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    webhook_endpoint_id BIGINT UNSIGNED NOT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(32) NOT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL,
    last_attempt_at DATETIME(6) NULL,
    last_status_code INT UNSIGNED NULL,
    last_error VARCHAR(255) NULL,
    signature_header VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    delivered_at DATETIME(6) NULL,
    PRIMARY KEY (delivery_id),
    KEY idx_webhook_deliveries_org_endpoint_status (organization_id, webhook_endpoint_id, status, created_at),
    KEY idx_webhook_deliveries_endpoint_org (webhook_endpoint_id, organization_id),
    KEY idx_webhook_deliveries_retry (status, next_attempt_at, created_at, delivery_id),
    KEY idx_webhook_deliveries_event_time (event_type, created_at),
    CONSTRAINT chk_webhook_deliveries_status CHECK (status IN ('queued', 'delivered', 'failed', 'exhausted')),
    CONSTRAINT chk_webhook_deliveries_last_status_code CHECK (last_status_code IS NULL OR last_status_code BETWEEN 100 AND 599),
    CONSTRAINT fk_webhook_deliveries_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_webhook_deliveries_endpoint
        FOREIGN KEY (webhook_endpoint_id, organization_id) REFERENCES webhook_endpoints (id, organization_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE webhook_delivery_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id VARCHAR(160) NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    status_code INT UNSIGNED NULL,
    error VARCHAR(255) NULL,
    signature_header VARCHAR(255) NULL,
    attempted_at DATETIME(6) NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_delivery_attempts_delivery_attempt (delivery_id, attempt_number),
    CONSTRAINT chk_webhook_delivery_attempts_attempt_positive CHECK (attempt_number > 0),
    CONSTRAINT chk_webhook_delivery_attempts_status_code CHECK (status_code IS NULL OR status_code BETWEEN 100 AND 599),
    CONSTRAINT fk_webhook_delivery_attempts_delivery
        FOREIGN KEY (delivery_id) REFERENCES webhook_deliveries (delivery_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS webhook_delivery_attempts');
        $this->execute('DROP TABLE IF EXISTS webhook_deliveries');
    }
}
