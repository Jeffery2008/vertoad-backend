<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSystemConfigVersionTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE system_config_versions (
    version_id VARCHAR(80) NOT NULL,
    config_key VARCHAR(160) NOT NULL,
    version INT UNSIGNED NOT NULL,
    value_json JSON NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (version_id),
    UNIQUE KEY uq_system_config_versions_key_version (config_key, version),
    KEY idx_system_config_versions_key_created (config_key, created_at),
    CONSTRAINT fk_system_config_versions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS system_config_versions');
    }
}
