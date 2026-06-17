<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateIpGeoStoreTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE ip_geo_records (
    ip_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    country_code VARCHAR(16) NULL,
    country_name VARCHAR(160) NULL,
    region_code VARCHAR(64) NULL,
    region_name VARCHAR(160) NULL,
    city_name VARCHAR(160) NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    timezone VARCHAR(80) NULL,
    canonical_geo_code VARCHAR(255) NULL,
    provider_id VARCHAR(120) NOT NULL,
    resolved_at DATETIME NOT NULL,
    raw_payload_hash CHAR(64) NOT NULL,
    raw_payload_summary_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_hash),
    KEY idx_ip_geo_records_ip_address (ip_address),
    KEY idx_ip_geo_records_provider_resolved (provider_id, resolved_at),
    KEY idx_ip_geo_records_canonical_resolved (canonical_geo_code, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ip_geo_lookup_tasks (
    ip_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(512) NULL,
    region_hint VARCHAR(64) NULL,
    request_id VARCHAR(160) NULL,
    request_ids_json JSON NOT NULL,
    source VARCHAR(64) NOT NULL DEFAULT 'unknown',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    provider_id VARCHAR(120) NULL,
    last_error VARCHAR(255) NULL,
    next_attempt_at DATETIME NULL,
    leased_until DATETIME NULL,
    lease_token VARCHAR(64) NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_hash),
    KEY idx_ip_geo_tasks_ip_created (ip_address, created_at),
    KEY idx_ip_geo_tasks_status_next (status, next_attempt_at),
    KEY idx_ip_geo_tasks_lease_token (lease_token),
    KEY idx_ip_geo_tasks_request_created (request_id, created_at),
    KEY idx_ip_geo_tasks_source_status_created (source, status, created_at),
    CHECK (status IN ('pending', 'processing', 'failed', 'dead', 'resolved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ip_geo_lookup_request_ids (
    ip_hash CHAR(64) NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_hash, request_id),
    KEY idx_ip_geo_lookup_request_ids_request (request_id, created_at),
    CONSTRAINT fk_ip_geo_lookup_request_ids_task FOREIGN KEY (ip_hash) REFERENCES ip_geo_lookup_tasks (ip_hash) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS ip_geo_lookup_request_ids');
        $this->execute('DROP TABLE IF EXISTS ip_geo_lookup_tasks');
        $this->execute('DROP TABLE IF EXISTS ip_geo_records');
    }
}
