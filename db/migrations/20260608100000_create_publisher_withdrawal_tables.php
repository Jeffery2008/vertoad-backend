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
    amount_cny DECIMAL(18,2) NOT NULL,
    points_per_cny INT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    payment_status VARCHAR(32) NOT NULL DEFAULT 'not_started',
    payout_method VARCHAR(64) NOT NULL,
    payout_account_json JSON NOT NULL,
    applicant_notes TEXT NULL,
    reviewer_user_id BIGINT UNSIGNED NULL,
    reviewer_notes TEXT NULL,
    payment_proof_id BIGINT UNSIGNED NULL,
    payment_completed_by_user_id BIGINT UNSIGNED NULL,
    payment_notes TEXT NULL,
    ledger_entry_id BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejected_at DATETIME NULL,
    revoked_at DATETIME NULL,
    resubmitted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_withdrawal_requests_id_organization (id, organization_id),
    UNIQUE KEY uq_withdrawal_requests_org_idempotency (organization_id, idempotency_key),
    UNIQUE KEY uq_withdrawal_requests_ledger (ledger_entry_id),
    KEY idx_withdrawal_requests_organization_review (organization_id, review_status, requested_at),
    KEY idx_withdrawal_requests_payment (payment_status, requested_at),
    KEY idx_withdrawal_requests_payment_proof_request (payment_proof_id, id),
    KEY idx_withdrawal_requests_requested_by (requested_by_user_id),
    KEY idx_withdrawal_requests_reviewer (reviewer_user_id),
    KEY idx_withdrawal_requests_payment_completed_by (payment_completed_by_user_id),
    CONSTRAINT fk_withdrawal_requests_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_payment_completed_by FOREIGN KEY (payment_completed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_requests_ledger FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CONSTRAINT chk_withdrawal_requests_review_status CHECK (review_status IN ('pending', 'approved', 'rejected', 'revoked')),
    CONSTRAINT chk_withdrawal_requests_payment_status CHECK (payment_status IN ('not_started', 'pending', 'paid')),
    CONSTRAINT chk_withdrawal_requests_points_positive CHECK (points_amount > 0),
    CONSTRAINT chk_withdrawal_requests_conversion CHECK (points_per_cny = 100 AND amount_cny = points_amount / 100),
    CONSTRAINT chk_withdrawal_requests_idempotency CHECK (CHAR_LENGTH(TRIM(idempotency_key)) BETWEEN 1 AND 160),
    CONSTRAINT chk_withdrawal_requests_payout_method CHECK (payout_method REGEXP '^[a-z][a-z0-9_.-]{0,63}$'),
    CONSTRAINT chk_withdrawal_requests_payout_account CHECK (JSON_TYPE(payout_account_json) = 'OBJECT' AND JSON_LENGTH(payout_account_json) > 0),
    CONSTRAINT chk_withdrawal_requests_notes CHECK (
        (applicant_notes IS NULL OR CHAR_LENGTH(applicant_notes) <= 2000)
        AND (reviewer_notes IS NULL OR CHAR_LENGTH(reviewer_notes) <= 2000)
        AND (payment_notes IS NULL OR CHAR_LENGTH(payment_notes) <= 2000)
    ),
    CONSTRAINT chk_withdrawal_requests_state_roles CHECK (
        (
            review_status = 'pending'
            AND payment_status = 'not_started'
            AND reviewer_user_id IS NULL AND reviewer_notes IS NULL AND reviewed_at IS NULL
            AND approved_at IS NULL AND rejected_at IS NULL AND revoked_at IS NULL
            AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL
        )
        OR (
            review_status = 'rejected'
            AND payment_status = 'not_started'
            AND reviewer_user_id IS NOT NULL AND reviewer_notes IS NOT NULL AND CHAR_LENGTH(TRIM(reviewer_notes)) > 0
            AND reviewed_at IS NOT NULL AND rejected_at IS NOT NULL
            AND approved_at IS NULL AND revoked_at IS NULL
            AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL
        )
        OR (
            review_status = 'revoked'
            AND payment_status = 'not_started'
            AND reviewer_user_id IS NULL AND reviewer_notes IS NULL AND reviewed_at IS NULL
            AND approved_at IS NULL AND rejected_at IS NULL AND revoked_at IS NOT NULL
            AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL
        )
        OR (
            review_status = 'approved'
            AND payment_status = 'pending'
            AND reviewer_user_id IS NOT NULL AND reviewed_at IS NOT NULL AND approved_at IS NOT NULL
            AND rejected_at IS NULL AND revoked_at IS NULL
            AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL
        )
        OR (
            review_status = 'approved'
            AND payment_status = 'paid'
            AND reviewer_user_id IS NOT NULL AND reviewed_at IS NOT NULL AND approved_at IS NOT NULL
            AND rejected_at IS NULL AND revoked_at IS NULL
            AND payment_proof_id IS NOT NULL AND payment_completed_by_user_id IS NOT NULL
            AND payment_notes IS NOT NULL AND CHAR_LENGTH(TRIM(payment_notes)) > 0 AND paid_at IS NOT NULL
        )
    )
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
    checksum VARCHAR(71) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_upload',
    verification_error_code VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verification_attempted_at DATETIME NULL,
    verified_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_withdrawal_proofs_object_key (object_key),
    UNIQUE KEY uq_withdrawal_proofs_id_request_organization (id, withdrawal_request_id, organization_id),
    KEY idx_withdrawal_proofs_request_status (withdrawal_request_id, status),
    KEY idx_withdrawal_proofs_request_organization (withdrawal_request_id, organization_id),
    KEY idx_withdrawal_proofs_uploaded_by (uploaded_by_user_id),
    CONSTRAINT fk_withdrawal_proofs_request_organization FOREIGN KEY (withdrawal_request_id, organization_id) REFERENCES withdrawal_requests (id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_proofs_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_withdrawal_proofs_status CHECK (status IN ('pending_upload', 'verified', 'rejected')),
    CONSTRAINT chk_withdrawal_proofs_object_key CHECK (object_key REGEXP '^withdrawals/[0-9]+/[0-9]+/[A-Za-z0-9_-]+-[A-Za-z0-9._-]+$'),
    CONSTRAINT chk_withdrawal_proofs_content_type CHECK (content_type IN ('application/pdf', 'image/jpeg', 'image/png')),
    CONSTRAINT chk_withdrawal_proofs_byte_size CHECK (byte_size BETWEEN 1 AND 10485760),
    CONSTRAINT chk_withdrawal_proofs_verification CHECK (
        (status = 'pending_upload' AND checksum IS NULL AND verification_error_code IS NULL AND verification_attempted_at IS NULL AND verified_at IS NULL)
        OR (status = 'rejected' AND checksum IS NULL AND verification_error_code IS NOT NULL AND verification_attempted_at IS NOT NULL AND verified_at IS NULL)
        OR (
            status = 'verified'
            AND checksum REGEXP '^sha256:[0-9a-f]{64}$'
            AND verification_error_code IS NULL
            AND verification_attempted_at IS NOT NULL
            AND verified_at IS NOT NULL
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE withdrawal_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    withdrawal_request_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    from_review_status VARCHAR(32) NULL,
    to_review_status VARCHAR(32) NOT NULL,
    from_payment_status VARCHAR(32) NULL,
    to_payment_status VARCHAR(32) NOT NULL,
    proof_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_withdrawal_audit_events_request_created (withdrawal_request_id, created_at),
    KEY idx_withdrawal_audit_events_request_organization (withdrawal_request_id, organization_id),
    KEY idx_withdrawal_audit_events_proof_request_organization (proof_id, withdrawal_request_id, organization_id),
    KEY idx_withdrawal_audit_events_actor (actor_user_id),
    CONSTRAINT fk_withdrawal_audit_events_request_organization FOREIGN KEY (withdrawal_request_id, organization_id) REFERENCES withdrawal_requests (id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_audit_events_proof_request_organization FOREIGN KEY (proof_id, withdrawal_request_id, organization_id) REFERENCES withdrawal_proofs (id, withdrawal_request_id, organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawal_audit_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_withdrawal_audit_events_action CHECK (
        action IN (
            'requested', 'approved', 'paid', 'rejected', 'revoked', 'resubmitted',
            'proof_upload_created', 'proof_verified', 'proof_rejected', 'proof_verification_deferred'
        )
    ),
    CONSTRAINT chk_withdrawal_audit_events_statuses CHECK (
        (from_review_status IS NULL OR from_review_status IN ('pending', 'approved', 'rejected', 'revoked'))
        AND to_review_status IN ('pending', 'approved', 'rejected', 'revoked')
        AND (from_payment_status IS NULL OR from_payment_status IN ('not_started', 'pending', 'paid'))
        AND to_payment_status IN ('not_started', 'pending', 'paid')
    ),
    CONSTRAINT chk_withdrawal_audit_events_transition CHECK (
        (action = 'requested' AND from_review_status IS NULL AND from_payment_status IS NULL AND to_review_status = 'pending' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'approved' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'approved' AND to_payment_status = 'pending' AND proof_id IS NULL)
        OR (action = 'rejected' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'rejected' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'revoked' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'revoked' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'resubmitted' AND from_review_status IN ('rejected', 'revoked') AND from_payment_status = 'not_started' AND to_review_status = 'pending' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'paid' AND from_review_status = 'approved' AND from_payment_status = 'pending' AND to_review_status = 'approved' AND to_payment_status = 'paid' AND proof_id IS NOT NULL)
        OR (action IN ('proof_upload_created', 'proof_verified', 'proof_rejected', 'proof_verification_deferred') AND from_review_status = 'approved' AND from_payment_status = 'pending' AND to_review_status = 'approved' AND to_payment_status = 'pending' AND proof_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE withdrawal_requests
    ADD CONSTRAINT fk_withdrawal_requests_payment_proof FOREIGN KEY (payment_proof_id, id) REFERENCES withdrawal_proofs (id, withdrawal_request_id) ON DELETE RESTRICT
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_withdrawal_proofs_ready_insert
BEFORE INSERT ON withdrawal_proofs
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM withdrawal_requests
        WHERE id = NEW.withdrawal_request_id
          AND organization_id = NEW.organization_id
          AND review_status = 'approved'
          AND payment_status = 'pending'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'withdrawal proof requires an approved pending payment';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_withdrawal_requests_paid_proof_insert
BEFORE INSERT ON withdrawal_requests
FOR EACH ROW
BEGIN
    IF NEW.payment_status = 'paid' AND NOT EXISTS (
        SELECT 1 FROM withdrawal_proofs
        WHERE id = NEW.payment_proof_id
          AND withdrawal_request_id = NEW.id
          AND organization_id = NEW.organization_id
          AND status = 'verified'
          AND checksum REGEXP '^sha256:[0-9a-f]{64}$'
          AND verified_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid withdrawal requires a verified payment proof';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_withdrawal_requests_paid_proof_update
BEFORE UPDATE ON withdrawal_requests
FOR EACH ROW
BEGIN
    IF NEW.payment_status = 'paid' AND NOT EXISTS (
        SELECT 1 FROM withdrawal_proofs
        WHERE id = NEW.payment_proof_id
          AND withdrawal_request_id = NEW.id
          AND organization_id = NEW.organization_id
          AND status = 'verified'
          AND checksum REGEXP '^sha256:[0-9a-f]{64}$'
          AND verified_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid withdrawal requires a verified payment proof';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_withdrawal_proofs_preserve_paid_verification
BEFORE UPDATE ON withdrawal_proofs
FOR EACH ROW
BEGIN
    IF NOT (NEW.withdrawal_request_id <=> OLD.withdrawal_request_id)
        OR NOT (NEW.organization_id <=> OLD.organization_id)
        OR NOT (NEW.uploaded_by_user_id <=> OLD.uploaded_by_user_id)
        OR NOT (NEW.object_key <=> OLD.object_key)
        OR NOT (NEW.content_type <=> OLD.content_type)
        OR NOT (NEW.byte_size <=> OLD.byte_size)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'withdrawal proof upload identity is immutable';
    END IF;

    IF OLD.status = 'pending_upload'
        AND NEW.status IN ('verified', 'rejected')
        AND NOT EXISTS (
            SELECT 1 FROM withdrawal_requests
            WHERE id = OLD.withdrawal_request_id
              AND organization_id = OLD.organization_id
              AND review_status = 'approved'
              AND payment_status = 'pending'
        )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'withdrawal proof verification requires an approved pending payment';
    END IF;

    IF EXISTS (
        SELECT 1 FROM withdrawal_requests
        WHERE payment_proof_id = OLD.id
          AND id = OLD.withdrawal_request_id
          AND organization_id = OLD.organization_id
          AND payment_status = 'paid'
    ) AND (
        NOT (NEW.status <=> OLD.status)
        OR NOT (NEW.checksum <=> OLD.checksum)
        OR NOT (NEW.verification_error_code <=> OLD.verification_error_code)
        OR NOT (NEW.verification_attempted_at <=> OLD.verification_attempted_at)
        OR NOT (NEW.verified_at <=> OLD.verified_at)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid withdrawal proof verification is immutable';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_withdrawal_proofs_preserve_paid_verification');
        $this->execute('DROP TRIGGER IF EXISTS trg_withdrawal_requests_paid_proof_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_withdrawal_requests_paid_proof_insert');
        $this->execute('DROP TRIGGER IF EXISTS trg_withdrawal_proofs_ready_insert');
        $this->execute('DROP TABLE IF EXISTS withdrawal_audit_events');
        $this->execute('ALTER TABLE withdrawal_requests DROP FOREIGN KEY fk_withdrawal_requests_payment_proof');
        $this->execute('DROP TABLE IF EXISTS withdrawal_proofs');
        $this->execute('DROP TABLE IF EXISTS withdrawal_requests');
        $this->execute('DROP TABLE IF EXISTS publisher_earning_events');
        $this->execute('DROP TABLE IF EXISTS revenue_share_rules');
    }
}
