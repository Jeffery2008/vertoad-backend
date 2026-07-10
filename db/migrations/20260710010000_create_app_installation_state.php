<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAppInstallationState extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE app_installations (
    id TINYINT UNSIGNED NOT NULL,
    installation_id CHAR(32) NOT NULL,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    initial_oauth_client_id BIGINT UNSIGNED NOT NULL,
    installed_at DATETIME NOT NULL,
    installed_by_ip VARBINARY(16) NULL,
    request_id VARCHAR(160) NOT NULL,
    metadata_json JSON NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_installations_installation_id (installation_id),
    CONSTRAINT fk_app_installations_admin_user FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_app_installations_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_app_installations_oauth_client FOREIGN KEY (initial_oauth_client_id) REFERENCES oauth_clients (id) ON DELETE RESTRICT,
    CONSTRAINT chk_app_installations_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS app_installations');
    }
}
