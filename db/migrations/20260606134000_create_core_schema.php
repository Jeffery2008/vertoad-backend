<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCoreSchema extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE organizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    billing_status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organizations_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_scope_slug (organization_id, slug),
    CONSTRAINT fk_roles_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(160) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE organization_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    title VARCHAR(120) NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_members_user (organization_id, user_id),
    KEY idx_organization_members_user (user_id),
    CONSTRAINT fk_organization_members_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    KEY idx_user_roles_organization (organization_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE system_config_versions (
    version_id VARCHAR(80) NOT NULL,
    config_key VARCHAR(160) NOT NULL,
    version INT UNSIGNED NOT NULL,
    value_json JSON NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version_id),
    UNIQUE KEY uq_system_config_versions_key_version (config_key, version),
    KEY idx_system_config_versions_key_created (config_key, created_at),
    KEY idx_system_config_versions_created_by (created_by_user_id),
    CONSTRAINT fk_system_config_versions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(160) NOT NULL,
    subject_type VARCHAR(120) NOT NULL,
    subject_id BIGINT UNSIGNED NULL,
    ip_address VARBINARY(16) NULL,
    user_agent VARCHAR(512) NULL,
    request_id VARCHAR(160) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_organization_created (organization_id, created_at),
    KEY idx_audit_logs_actor_created (actor_user_id, created_at),
    KEY idx_audit_logs_request_created (request_id, created_at),
    KEY idx_audit_logs_subject (subject_type, subject_id),
    CONSTRAINT fk_audit_logs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    points_amount BIGINT NOT NULL,
    direction ENUM('credit', 'debit') NOT NULL,
    balance_after_points BIGINT NULL,
    reference_type VARCHAR(120) NULL,
    reference_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    memo VARCHAR(255) NULL,
    metadata_json JSON NULL,
    reversal_of_ledger_entry_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE
            WHEN reference_type = 'ledger_entry'
                AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.entry_kind')) = 'reversal'
            THEN reference_id
            ELSE NULL
        END
    ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_entries_idempotency (idempotency_key),
    UNIQUE KEY uq_ledger_entries_single_reversal (reversal_of_ledger_entry_id),
    KEY idx_ledger_entries_organization_created (organization_id, created_at),
    KEY idx_ledger_entries_reference (reference_type, reference_id),
    CONSTRAINT fk_ledger_entries_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_entries_account_type CHECK (account_type IN ('advertiser_balance', 'publisher_earnings')),
    CONSTRAINT chk_ledger_entries_points_amount_positive CHECK (points_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only ledger: corrections are recorded as new entries; update/delete are blocked by triggers.'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ledger_account_balances (
    organization_id BIGINT UNSIGNED NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    balance_points BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, account_type),
    CONSTRAINT fk_ledger_account_balances_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_account_balances_account_type CHECK (account_type IN ('advertiser_balance', 'publisher_earnings'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Current ledger account balances maintained transactionally with ledger_entries.'
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_ledger_entries_no_update
BEFORE UPDATE ON ledger_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only; insert a correcting entry instead'
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_ledger_entries_no_delete
BEFORE DELETE ON ledger_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only; insert a reversing entry instead'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE recharge_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NULL,
    key_hash CHAR(64) NOT NULL,
    encrypted_plaintext_key VARBINARY(1024) NOT NULL,
    points_amount BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'issued',
    batch_code VARCHAR(120) NULL,
    batch_metadata_json JSON NULL,
    issued_by_user_id BIGINT UNSIGNED NULL,
    redeemed_by_user_id BIGINT UNSIGNED NULL,
    redeemed_ledger_entry_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NULL,
    redeemed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recharge_keys_key_hash (key_hash),
    KEY idx_recharge_keys_organization_status (organization_id, status),
    CONSTRAINT fk_recharge_keys_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_recharge_keys_issued_by FOREIGN KEY (issued_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_recharge_keys_redeemed_by FOREIGN KEY (redeemed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_recharge_keys_ledger_entry FOREIGN KEY (redeemed_ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE SET NULL,
    CONSTRAINT chk_recharge_keys_points_amount_positive CHECK (points_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    verification_token VARCHAR(120) NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sites_domain (domain),
    KEY idx_sites_organization_status (organization_id, status),
    CONSTRAINT fk_sites_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE ad_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slot_key VARCHAR(120) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    floor_cpm DECIMAL(12, 6) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ad_slots_site_slot_key (site_id, slot_key),
    KEY idx_ad_slots_status (status),
    CONSTRAINT fk_ad_slots_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    pause_reason VARCHAR(160) NULL,
    objective VARCHAR(64) NOT NULL DEFAULT 'traffic',
    budget_total DECIMAL(18, 6) NULL,
    budget_daily DECIMAL(18, 6) NULL,
    bid_cpm DECIMAL(12, 6) NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_campaigns_organization_status (organization_id, status),
    KEY idx_campaigns_schedule (starts_at, ends_at),
    CONSTRAINT fk_campaigns_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE creatives (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    creative_type VARCHAR(64) NOT NULL,
    asset_url VARCHAR(1024) NULL,
    landing_url VARCHAR(1024) NOT NULL,
    width SMALLINT UNSIGNED NULL,
    height SMALLINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_creatives_campaign_status (campaign_id, status),
    CONSTRAINT fk_creatives_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE raw_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid VARCHAR(255) NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    ad_slot_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    creative_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(64) NOT NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_ip VARBINARY(16) NULL,
    user_agent VARCHAR(512) NULL,
    payload_json JSON NOT NULL,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_raw_events_event_uuid_occurred (event_uuid, occurred_at),
    KEY idx_raw_events_event_uuid (event_uuid),
    KEY idx_raw_events_received (received_at),
    KEY idx_raw_events_processing (processed_at, received_at),
    KEY idx_raw_events_campaign_type_time (campaign_id, event_type, occurred_at),
    CONSTRAINT fk_raw_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_raw_events_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL,
    CONSTRAINT fk_raw_events_ad_slot FOREIGN KEY (ad_slot_id) REFERENCES ad_slots (id) ON DELETE SET NULL,
    CONSTRAINT fk_raw_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_raw_events_creative FOREIGN KEY (creative_id) REFERENCES creatives (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE raw_event_dedup (
    event_uuid VARCHAR(255) NOT NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_uuid),
    KEY idx_raw_event_dedup_occurred_at (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE error_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NULL,
    severity VARCHAR(32) NOT NULL,
    source VARCHAR(120) NOT NULL,
    message VARCHAR(1024) NOT NULL,
    exception_class VARCHAR(255) NULL,
    trace_id VARCHAR(120) NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_error_logs_created (created_at),
    KEY idx_error_logs_severity_source (severity, source),
    KEY idx_error_logs_trace_id (trace_id),
    CONSTRAINT fk_error_logs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS error_logs');
        $this->execute('DROP TABLE IF EXISTS raw_event_dedup');
        $this->execute('DROP TABLE IF EXISTS raw_events');
        $this->execute('DROP TABLE IF EXISTS creatives');
        $this->execute('DROP TABLE IF EXISTS campaigns');
        $this->execute('DROP TABLE IF EXISTS ad_slots');
        $this->execute('DROP TABLE IF EXISTS sites');
        $this->execute('DROP TABLE IF EXISTS recharge_keys');
        $this->execute('DROP TRIGGER IF EXISTS trg_ledger_entries_no_delete');
        $this->execute('DROP TRIGGER IF EXISTS trg_ledger_entries_no_update');
        $this->execute('DROP TABLE IF EXISTS ledger_account_balances');
        $this->execute('DROP TABLE IF EXISTS ledger_entries');
        $this->execute('DROP TABLE IF EXISTS audit_logs');
        $this->execute('DROP TABLE IF EXISTS system_config_versions');
        $this->execute('DROP TABLE IF EXISTS user_roles');
        $this->execute('DROP TABLE IF EXISTS organization_members');
        $this->execute('DROP TABLE IF EXISTS role_permissions');
        $this->execute('DROP TABLE IF EXISTS permissions');
        $this->execute('DROP TABLE IF EXISTS roles');
        $this->execute('DROP TABLE IF EXISTS organizations');
        $this->execute('DROP TABLE IF EXISTS users');
    }
}
