<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAssetUploadTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE asset_upload_intents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    uploader_user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    object_key VARCHAR(512) NOT NULL,
    content_type VARCHAR(120) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_upload_intents_object_key (object_key),
    KEY idx_asset_upload_intents_organization_status (organization_id, status),
    CONSTRAINT fk_asset_upload_intents_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_upload_intents_uploader FOREIGN KEY (uploader_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_asset_upload_intents_byte_size_positive CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE creative_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    upload_intent_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    uploader_user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    object_key VARCHAR(512) NOT NULL,
    content_type VARCHAR(120) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    duration_seconds DECIMAL(10,3) NULL,
    checksum VARCHAR(160) NULL,
    status VARCHAR(32) NOT NULL,
    snapshot_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    snapshot_png_object_key VARCHAR(512) NULL,
    snapshot_webp_object_key VARCHAR(512) NULL,
    thumbnail_webp_object_key VARCHAR(512) NULL,
    snapshot_completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_creative_assets_upload_intent (upload_intent_id),
    UNIQUE KEY uq_creative_assets_object_key (object_key),
    UNIQUE KEY uq_creative_assets_snapshot_png_key (snapshot_png_object_key),
    UNIQUE KEY uq_creative_assets_snapshot_webp_key (snapshot_webp_object_key),
    UNIQUE KEY uq_creative_assets_thumbnail_webp_key (thumbnail_webp_object_key),
    KEY idx_creative_assets_organization_status (organization_id, status),
    KEY idx_creative_assets_snapshot_status (snapshot_status, created_at),
    CONSTRAINT fk_creative_assets_upload_intent FOREIGN KEY (upload_intent_id) REFERENCES asset_upload_intents (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_assets_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_creative_assets_uploader FOREIGN KEY (uploader_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_creative_assets_byte_size_positive CHECK (byte_size > 0),
    CONSTRAINT chk_creative_assets_dimensions_positive CHECK (width > 0 AND height > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE asset_snapshot_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token VARCHAR(64) NULL,
    lease_expires_at DATETIME NULL,
    last_error_code VARCHAR(80) NULL,
    last_error_message VARCHAR(1000) NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_snapshot_jobs_asset (asset_id),
    KEY idx_asset_snapshot_jobs_status_created (status, created_at),
    KEY idx_asset_snapshot_jobs_claim (status, available_at, lease_expires_at, id),
    KEY idx_asset_snapshot_jobs_organization (organization_id),
    CONSTRAINT fk_asset_snapshot_jobs_asset FOREIGN KEY (asset_id) REFERENCES creative_assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_snapshot_jobs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS asset_snapshot_jobs');
        $this->execute('DROP TABLE IF EXISTS creative_assets');
        $this->execute('DROP TABLE IF EXISTS asset_upload_intents');
    }
}
