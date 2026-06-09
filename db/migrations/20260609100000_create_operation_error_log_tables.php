<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOperationErrorLogTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE operation_error_logs (
    error_id VARCHAR(160) NOT NULL,
    request_id VARCHAR(160) NOT NULL,
    severity VARCHAR(32) NOT NULL,
    message VARCHAR(1024) NOT NULL,
    redacted_context_json JSON NOT NULL,
    raw_context_json JSON NULL,
    source VARCHAR(32) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (error_id),
    KEY idx_operation_error_logs_occurred (occurred_at, error_id),
    KEY idx_operation_error_logs_request (request_id, occurred_at),
    KEY idx_operation_error_logs_source_severity (source, severity, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS operation_error_logs');
    }
}
