<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCreativeTemplateDesignTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE creative_templates (
    template_id VARCHAR(80) NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    fabric_json JSON NOT NULL,
    snapshot_url VARCHAR(1024) NULL,
    tags_json JSON NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (template_id),
    KEY idx_creative_templates_scope_updated (organization_id, updated_at),
    KEY idx_creative_templates_created_by (created_by_user_id),
    CONSTRAINT fk_creative_templates_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_templates_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_creative_templates_dimensions_positive CHECK (width > 0 AND height > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE creative_designs (
    design_id VARCHAR(80) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    current_version INT UNSIGNED NOT NULL,
    template_id VARCHAR(80) NULL,
    status VARCHAR(32) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (design_id),
    KEY idx_creative_designs_organization_updated (organization_id, updated_at),
    KEY idx_creative_designs_template (template_id),
    CONSTRAINT fk_creative_designs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_designs_template FOREIGN KEY (template_id) REFERENCES creative_templates (template_id) ON DELETE SET NULL,
    CONSTRAINT fk_creative_designs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_creative_designs_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_creative_designs_dimensions_positive CHECK (width > 0 AND height > 0),
    CONSTRAINT chk_creative_designs_current_version_positive CHECK (current_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE creative_design_versions (
    version_id VARCHAR(80) NOT NULL,
    design_id VARCHAR(80) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    fabric_json JSON NOT NULL,
    snapshot_url VARCHAR(1024) NULL,
    change_summary VARCHAR(512) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (version_id),
    UNIQUE KEY uq_creative_design_versions_number (design_id, version_number),
    KEY idx_creative_design_versions_design_created (design_id, created_at),
    KEY idx_creative_design_versions_created_by (created_by_user_id),
    CONSTRAINT fk_creative_design_versions_design FOREIGN KEY (design_id) REFERENCES creative_designs (design_id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_design_versions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_creative_design_versions_number_positive CHECK (version_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS creative_design_versions');
        $this->execute('DROP TABLE IF EXISTS creative_designs');
        $this->execute('DROP TABLE IF EXISTS creative_templates');
    }
}
