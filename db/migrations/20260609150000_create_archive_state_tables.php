<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateArchiveStateTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE archive_manifests (
    manifest_id VARCHAR(80) NOT NULL,
    status VARCHAR(32) NOT NULL,
    format VARCHAR(32) NOT NULL,
    event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    partitions_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manifest_id),
    KEY idx_archive_manifests_created (created_at),
    KEY idx_archive_manifests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE archive_cold_query_jobs (
    job_id VARCHAR(80) NOT NULL,
    status VARCHAR(32) NOT NULL,
    sql_text TEXT NOT NULL,
    parameters_json JSON NOT NULL,
    requested_by VARCHAR(160) NOT NULL,
    result_format VARCHAR(32) NOT NULL,
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    result_object_key VARCHAR(1024) NULL,
    scanned_object_keys_json JSON NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (job_id),
    KEY idx_archive_cold_query_jobs_status_created (status, created_at),
    KEY idx_archive_cold_query_jobs_requested_by (requested_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS archive_cold_query_jobs');
        $this->execute('DROP TABLE IF EXISTS archive_manifests');
    }
}
