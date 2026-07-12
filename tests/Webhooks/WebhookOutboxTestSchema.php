<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use Doctrine\DBAL\Connection;

final class WebhookOutboxTestSchema
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE webhook_endpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id VARCHAR(160) NOT NULL UNIQUE,
    organization_id INTEGER NOT NULL,
    created_by_user_id INTEGER NOT NULL,
    name VARCHAR(160) NOT NULL,
    endpoint_url TEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    encrypted_signing_secret TEXT NOT NULL,
    secret_preview VARCHAR(64) NOT NULL,
    secret_rotated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL,
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE webhook_endpoint_events (
    webhook_endpoint_id INTEGER NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    PRIMARY KEY (webhook_endpoint_id, event_type)
)
SQL,
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE webhook_deliveries (
    delivery_id VARCHAR(160) PRIMARY KEY,
    organization_id INTEGER NOT NULL,
    webhook_endpoint_id INTEGER NOT NULL,
    endpoint_url TEXT NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payload_json TEXT NOT NULL,
    request_id TEXT NULL,
    status VARCHAR(32) NOT NULL,
    retry_count INTEGER NOT NULL,
    next_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NULL,
    last_status_code INTEGER NULL,
    last_error TEXT NULL,
    signature_header TEXT NULL,
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL
)
SQL,
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE webhook_delivery_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_id VARCHAR(160) NOT NULL,
    attempt_number INTEGER NOT NULL,
    status_code INTEGER NULL,
    error TEXT NULL,
    signature_header TEXT NULL,
    attempted_at DATETIME NOT NULL,
    duration_ms INTEGER NOT NULL,
    UNIQUE (delivery_id, attempt_number)
)
SQL,
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE webhook_outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id VARCHAR(160) NOT NULL UNIQUE,
    organization_id INTEGER NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    api_version VARCHAR(32) NOT NULL,
    aggregate_type VARCHAR(80) NOT NULL,
    aggregate_id VARCHAR(160) NOT NULL,
    data_json TEXT NOT NULL,
    request_id VARCHAR(160) NULL,
    occurred_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    lease_token VARCHAR(160) NULL,
    lease_expires_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    dispatched_at DATETIME NULL
)
SQL,
        );
    }

    private function __construct()
    {
    }
}
