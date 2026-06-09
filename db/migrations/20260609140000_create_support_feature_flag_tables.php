<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSupportFeatureFlagTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE support_tickets (
    ticket_id VARCHAR(64) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priority VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    linked_entity_json JSON NOT NULL,
    assignee_user_id BIGINT UNSIGNED NULL,
    internal_notes_json JSON NOT NULL,
    attachments_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (ticket_id),
    KEY idx_support_tickets_organization_status (organization_id, status),
    KEY idx_support_tickets_assignee_status (assignee_user_id, status),
    KEY idx_support_tickets_updated (updated_at),
    CONSTRAINT fk_support_tickets_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_support_tickets_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_support_tickets_assignee FOREIGN KEY (assignee_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE feature_flags (
    flag_key VARCHAR(128) NOT NULL,
    environment VARCHAR(32) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    targets_json JSON NOT NULL,
    percentage_rollout TINYINT UNSIGNED NOT NULL DEFAULT 0,
    time_window_json JSON NULL,
    published TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (flag_key),
    KEY idx_feature_flags_environment_published (environment, published),
    KEY idx_feature_flags_updated (updated_at),
    CONSTRAINT chk_feature_flags_rollout CHECK (percentage_rollout <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS feature_flags');
        $this->execute('DROP TABLE IF EXISTS support_tickets');
    }
}
