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
    endpoint_url VARCHAR(2048) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(32) NOT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    signature_header VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    delivered_at DATETIME NULL,
    PRIMARY KEY (delivery_id),
    KEY idx_webhook_deliveries_retry (status, created_at, delivery_id),
    KEY idx_webhook_deliveries_event_time (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS webhook_deliveries');
    }
}
