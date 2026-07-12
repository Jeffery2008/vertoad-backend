<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOperationBackupJobs extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'sqlite') {
            $this->createSqliteTable();

            return;
        }

        $this->execute(<<<'SQL'
CREATE TABLE operation_backup_jobs (
    job_id VARCHAR(64) NOT NULL,
    job_type VARCHAR(16) NOT NULL,
    source_backup_id VARCHAR(64) NULL,
    status VARCHAR(16) NOT NULL,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    environment VARCHAR(32) NOT NULL,
    reason VARCHAR(500) NULL,
    manifest_object_key VARCHAR(1024) NULL,
    manifest_sha256 CHAR(64) NULL,
    mysql_object_key VARCHAR(1024) NULL,
    mysql_sha256 CHAR(64) NULL,
    config_object_key VARCHAR(1024) NULL,
    evidence_object_key VARCHAR(1024) NULL,
    object_count INT UNSIGNED NOT NULL DEFAULT 0,
    byte_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    lease_owner VARCHAR(128) NULL,
    lease_expires_at DATETIME NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME NULL,
    active_restore_slot TINYINT UNSIGNED GENERATED ALWAYS AS (
        CASE
            WHEN job_type = 'restore' AND status IN ('queued', 'running') THEN 1
            ELSE NULL
        END
    ) STORED,
    PRIMARY KEY (job_id),
    UNIQUE KEY uq_operation_backup_jobs_active_restore (active_restore_slot),
    KEY idx_operation_backup_jobs_queue (job_type, status, lease_expires_at, created_at),
    KEY idx_operation_backup_jobs_completed (job_type, status, completed_at),
    KEY idx_operation_backup_jobs_source (source_backup_id, created_at),
    KEY idx_operation_backup_jobs_actor (requested_by_user_id, created_at),
    CONSTRAINT chk_operation_backup_jobs_type CHECK (job_type IN ('backup', 'restore')),
    CONSTRAINT chk_operation_backup_jobs_status CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    CONSTRAINT chk_operation_backup_jobs_running_lease CHECK (
        status <> 'running'
        OR (
            lease_owner IS NOT NULL
            AND lease_expires_at IS NOT NULL
            AND heartbeat_at IS NOT NULL
            AND attempt_count > 0
            AND heartbeat_at <= lease_expires_at
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS operation_backup_jobs');
    }

    private function createSqliteTable(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE operation_backup_jobs (
    job_id VARCHAR(64) NOT NULL PRIMARY KEY,
    job_type VARCHAR(16) NOT NULL,
    source_backup_id VARCHAR(64) NULL,
    status VARCHAR(16) NOT NULL,
    requested_by_user_id INTEGER NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    environment VARCHAR(32) NOT NULL,
    reason VARCHAR(500) NULL,
    manifest_object_key VARCHAR(1024) NULL,
    manifest_sha256 VARCHAR(64) NULL,
    mysql_object_key VARCHAR(1024) NULL,
    mysql_sha256 VARCHAR(64) NULL,
    config_object_key VARCHAR(1024) NULL,
    evidence_object_key VARCHAR(1024) NULL,
    object_count INTEGER NOT NULL DEFAULT 0,
    byte_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    lease_owner VARCHAR(128) NULL,
    lease_expires_at DATETIME NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    heartbeat_at DATETIME NULL,
    active_restore_slot INTEGER GENERATED ALWAYS AS (
        CASE
            WHEN job_type = 'restore' AND status IN ('queued', 'running') THEN 1
            ELSE NULL
        END
    ) STORED,
    CHECK (job_type IN ('backup', 'restore')),
    CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    CHECK (
        status <> 'running'
        OR (
            lease_owner IS NOT NULL
            AND lease_expires_at IS NOT NULL
            AND heartbeat_at IS NOT NULL
            AND attempt_count > 0
            AND heartbeat_at <= lease_expires_at
        )
    )
)
SQL);
        $this->execute(
            'CREATE UNIQUE INDEX uq_operation_backup_jobs_active_restore '
            . 'ON operation_backup_jobs (active_restore_slot)',
        );
        $this->execute(
            'CREATE INDEX idx_operation_backup_jobs_queue '
            . 'ON operation_backup_jobs (job_type, status, lease_expires_at, created_at)',
        );
        $this->execute(
            'CREATE INDEX idx_operation_backup_jobs_completed '
            . 'ON operation_backup_jobs (job_type, status, completed_at)',
        );
        $this->execute(
            'CREATE INDEX idx_operation_backup_jobs_source '
            . 'ON operation_backup_jobs (source_backup_id, created_at)',
        );
        $this->execute(
            'CREATE INDEX idx_operation_backup_jobs_actor '
            . 'ON operation_backup_jobs (requested_by_user_id, created_at)',
        );
    }
}
