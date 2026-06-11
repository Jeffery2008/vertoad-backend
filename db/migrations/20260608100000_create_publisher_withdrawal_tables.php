<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePublisherWithdrawalTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE revenue_share_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(32) NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    ad_slot_id BIGINT UNSIGNED NULL,
    share_ratio_bps INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    version INT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_revenue_share_rules_scope_target (scope, organization_id, site_id, ad_slot_id),
    KEY idx_revenue_share_rules_status_version (status, version),
    KEY idx_revenue_share_rules_organization (organization_id),
    KEY idx_revenue_share_rules_site (site_id),
    KEY idx_revenue_share_rules_ad_slot (ad_slot_id),
    KEY idx_revenue_share_rules_created_by (created_by_user_id),
    CONSTRAINT fk_revenue_share_rules_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_revenue_share_rules_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE RESTRICT,
    CONSTRAINT fk_revenue_share_rules_ad_slot FOREIGN KEY (ad_slot_id) REFERENCES ad_slots (id) ON DELETE RESTRICT,
    CONSTRAINT fk_revenue_share_rules_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_revenue_share_rules_scope CHECK (scope IN ('global', 'publisher', 'site', 'slot')),
    CONSTRAINT chk_revenue_share_rules_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT chk_revenue_share_rules_ratio CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CONSTRAINT chk_revenue_share_rules_scope_target CHECK (
        (scope = 'global' AND organization_id IS NULL AND site_id IS NULL AND ad_slot_id IS NULL)
        OR (scope = 'publisher' AND organization_id IS NOT NULL AND site_id IS NULL AND ad_slot_id IS NULL)
        OR (scope = 'site' AND organization_id IS NULL AND site_id IS NOT NULL AND ad_slot_id IS NULL)
        OR (scope = 'slot' AND organization_id IS NULL AND site_id IS NULL AND ad_slot_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE publisher_earning_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(160) NOT NULL,
    publisher_organization_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    ad_slot_id BIGINT UNSIGNED NOT NULL,
    advertiser_organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    gross_points BIGINT NOT NULL,
    share_ratio_bps INT NOT NULL,
    publisher_points BIGINT NOT NULL,
    platform_points BIGINT NOT NULL,
    revenue_share_rule_id BIGINT UNSIGNED NULL,
    ledger_entry_id BIGINT UNSIGNED NOT NULL,
    metadata_json JSON NULL,
    earned_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_publisher_earning_events_event_id (event_id),
    UNIQUE KEY uq_publisher_earning_events_ledger (ledger_entry_id),
    KEY idx_publisher_earning_events_publisher_created (publisher_organization_id, earned_at),
    KEY idx_publisher_earning_events_site (site_id),
    KEY idx_publisher_earning_events_ad_slot (ad_slot_id),
    KEY idx_publisher_earning_events_advertiser_org (advertiser_organization_id),
    KEY idx_publisher_earning_events_campaign (campaign_id),
    KEY idx_publisher_earning_events_rule (revenue_share_rule_id),
    CONSTRAINT fk_publisher_earning_events_publisher_org FOREIGN KEY (publisher_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_publisher_earning_events_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE RESTRICT,
    CONSTRAINT fk_publisher_earning_events_ad_slot FOREIGN KEY (ad_slot_id) REFERENCES ad_slots (id) ON DELETE RESTRICT,
    CONSTRAINT fk_publisher_earning_events_advertiser_org FOREIGN KEY (advertiser_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_publisher_earning_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_publisher_earning_events_rule FOREIGN KEY (revenue_share_rule_id) REFERENCES revenue_share_rules (id) ON DELETE SET NULL,
    CONSTRAINT fk_publisher_earning_events_ledger FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CONSTRAINT chk_publisher_earning_events_ratio CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CONSTRAINT chk_publisher_earning_events_points CHECK (gross_points >= 0 AND publisher_points >= 0 AND platform_points >= 0 AND gross_points = publisher_points + platform_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE withdrawal_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    points_amount BIGINT NOT NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL,
    payout_method VARCHAR(64) NOT NULL,
    payout_account_json JSON NOT NULL,
    applicant_notes TEXT NULL,
    reviewer_user_id BIGINT UNSIGNED NULL,
    reviewer_notes TEXT NULL,
    ledger_entry_id BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejected_at DATETIME NULL,
    revoked_at DATETIME NULL,
    resubmitted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_withdrawal_requests_org_idempotency (organization_id, idempotency_key),
    UNIQUE KEY uq_withdrawal_requests_ledger (ledger_entry_id),
    KEY idx_withdrawal_requests_organization_status (organization_id, status),
    KEY idx_withdrawal_requests_requested_by (requested_by_user_id),
    KEY idx_withdrawal_requests_reviewer (reviewer_user_id),
    CONSTRAINT fk_withdrawal_requests_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_withdrawal_requests_ledger FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CONSTRAINT chk_withdrawal_requests_status CHECK (status IN ('requested', 'paid', 'rejected', 'revoked')),
    CONSTRAINT chk_withdrawal_requests_points_positive CHECK (points_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE withdrawal_proofs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    withdrawal_request_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    object_key VARCHAR(512) NOT NULL,
    content_type VARCHAR(120) NOT NULL,
    byte_size BIGINT NOT NULL,
    checksum VARCHAR(160) NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_withdrawal_proofs_object_key (object_key),
    KEY idx_withdrawal_proofs_request_status (withdrawal_request_id, status),
    KEY idx_withdrawal_proofs_organization (organization_id),
    KEY idx_withdrawal_proofs_uploaded_by (uploaded_by_user_id),
    CONSTRAINT fk_withdrawal_proofs_request FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_withdrawal_proofs_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_proofs_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_withdrawal_proofs_status CHECK (status IN ('pending_upload', 'confirmed')),
    CONSTRAINT chk_withdrawal_proofs_byte_size_positive CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE withdrawal_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    withdrawal_request_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    notes TEXT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_withdrawal_audit_events_request_created (withdrawal_request_id, created_at),
    KEY idx_withdrawal_audit_events_organization (organization_id),
    KEY idx_withdrawal_audit_events_actor (actor_user_id),
    CONSTRAINT fk_withdrawal_audit_events_request FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_withdrawal_audit_events_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_audit_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_withdrawal_audit_events_action CHECK (action IN ('requested', 'paid', 'rejected', 'revoked', 'resubmitted')),
    CONSTRAINT chk_withdrawal_audit_events_statuses CHECK (
        (from_status IS NULL OR from_status IN ('requested', 'paid', 'rejected', 'revoked'))
        AND to_status IN ('requested', 'paid', 'rejected', 'revoked')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS withdrawal_audit_events');
        $this->execute('DROP TABLE IF EXISTS withdrawal_proofs');
        $this->execute('DROP TABLE IF EXISTS withdrawal_requests');
        $this->execute('DROP TABLE IF EXISTS publisher_earning_events');
        $this->execute('DROP TABLE IF EXISTS revenue_share_rules');
    }
}
