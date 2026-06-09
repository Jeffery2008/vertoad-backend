<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWebhookEndpointTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE webhook_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint_id VARCHAR(160) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    status VARCHAR(32) NOT NULL,
    encrypted_signing_secret TEXT NOT NULL,
    secret_preview VARCHAR(64) NOT NULL,
    secret_rotated_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_endpoints_endpoint_id (endpoint_id),
    UNIQUE KEY uq_webhook_endpoints_id_org (id, organization_id),
    KEY idx_webhook_endpoints_org_status (organization_id, status),
    KEY idx_webhook_endpoints_created_by (created_by_user_id),
    CONSTRAINT chk_webhook_endpoints_status CHECK (status IN ('active', 'paused')),
    CONSTRAINT fk_webhook_endpoints_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_webhook_endpoints_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE webhook_endpoint_events (
    webhook_endpoint_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    PRIMARY KEY (webhook_endpoint_id, event_type),
    KEY idx_webhook_endpoint_events_type (event_type),
    CONSTRAINT chk_webhook_endpoint_events_type CHECK (event_type <> ''),
    CONSTRAINT fk_webhook_endpoint_events_endpoint
        FOREIGN KEY (webhook_endpoint_id) REFERENCES webhook_endpoints (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS webhook_endpoint_events');
        $this->execute('DROP TABLE IF EXISTS webhook_endpoints');
    }
}
