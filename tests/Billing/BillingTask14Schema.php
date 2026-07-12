<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use Doctrine\DBAL\Connection;

final class BillingTask14Schema
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    account_id INTEGER NULL,
    points_amount INTEGER NOT NULL,
    direction VARCHAR(16) NOT NULL,
    balance_after_points INTEGER NULL,
    reference_type VARCHAR(120) NULL,
    reference_id INTEGER NULL,
    idempotency_key VARCHAR(160) NOT NULL UNIQUE,
    memo VARCHAR(255) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ledger_account_balances (
    organization_id INTEGER NOT NULL,
    account_type VARCHAR(64) NOT NULL,
    balance_points INTEGER NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, account_type)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    name VARCHAR(200) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'verified'
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ad_slots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL,
    name VARCHAR(160) NOT NULL,
    slot_key VARCHAR(120) NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    floor_cpm NUMERIC NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active'
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ad_serving_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type VARCHAR(32) NOT NULL,
    event_id VARCHAR(160) NOT NULL,
    decision_id VARCHAR(160) NOT NULL,
    site_id INTEGER NOT NULL,
    slot_id INTEGER NOT NULL,
    viewer_id VARCHAR(160) NOT NULL,
    ad_id VARCHAR(160) NULL,
    campaign_id INTEGER NULL,
    advertiser_organization_id INTEGER NULL,
    publisher_organization_id INTEGER NULL,
    cost_points INTEGER NULL,
    occurred_at DATETIME NOT NULL,
    valid INTEGER NOT NULL,
    reason VARCHAR(120) NULL,
    visible_ratio NUMERIC NULL,
    visible_ms INTEGER NULL,
    request_id VARCHAR(128) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    geo_code VARCHAR(64) NULL,
    billing_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    billed_points INTEGER NOT NULL DEFAULT 0,
    publisher_earning_points INTEGER NOT NULL DEFAULT 0,
    billing_reason VARCHAR(120) NULL,
    billing_processed_at DATETIME NULL,
    processed_at DATETIME NULL,
    UNIQUE (event_type, event_id, occurred_at)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE ad_serving_event_dedup (
    event_type VARCHAR(32) NOT NULL,
    event_id VARCHAR(160) NOT NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_type, event_id)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE raw_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_uuid VARCHAR(255) NOT NULL,
    organization_id INTEGER NULL,
    site_id INTEGER NULL,
    ad_slot_id INTEGER NULL,
    campaign_id INTEGER NULL,
    creative_id INTEGER NULL,
    event_type VARCHAR(64) NOT NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    request_ip BLOB NULL,
    request_id VARCHAR(128) NULL,
    user_agent VARCHAR(512) NULL,
    payload_json TEXT NOT NULL,
    processed_at DATETIME NULL,
    UNIQUE (event_uuid, occurred_at)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE raw_event_dedup (
    event_uuid VARCHAR(255) NOT NULL PRIMARY KEY,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE revenue_share_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope VARCHAR(32) NOT NULL,
    organization_id INTEGER NULL,
    site_id INTEGER NULL,
    ad_slot_id INTEGER NULL,
    share_ratio_bps INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    version INTEGER NOT NULL,
    created_by_user_id INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (scope IN ('global', 'publisher', 'site', 'slot')),
    CHECK (status IN ('active', 'inactive')),
    CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CHECK (
        (scope = 'global' AND organization_id IS NULL AND site_id IS NULL AND ad_slot_id IS NULL)
        OR (scope = 'publisher' AND organization_id IS NOT NULL AND site_id IS NULL AND ad_slot_id IS NULL)
        OR (scope = 'site' AND organization_id IS NULL AND site_id IS NOT NULL AND ad_slot_id IS NULL)
        OR (scope = 'slot' AND organization_id IS NULL AND site_id IS NULL AND ad_slot_id IS NOT NULL)
    )
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE publisher_earning_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id VARCHAR(160) NOT NULL UNIQUE,
    publisher_organization_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    ad_slot_id INTEGER NOT NULL,
    advertiser_organization_id INTEGER NOT NULL,
    campaign_id INTEGER NULL,
    gross_points INTEGER NOT NULL,
    share_ratio_bps INTEGER NOT NULL,
    publisher_points INTEGER NOT NULL,
    platform_points INTEGER NOT NULL,
    revenue_share_rule_id INTEGER NULL,
    ledger_entry_id INTEGER NOT NULL,
    metadata_json TEXT NULL,
    earned_at DATETIME NOT NULL,
    UNIQUE (ledger_entry_id),
    FOREIGN KEY (revenue_share_rule_id) REFERENCES revenue_share_rules (id) ON DELETE SET NULL,
    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CHECK (gross_points >= 0 AND publisher_points >= 0 AND platform_points >= 0 AND gross_points = publisher_points + platform_points)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE cpm_billing_accumulators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stream_key CHAR(64) NOT NULL UNIQUE,
    advertiser_organization_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    publisher_organization_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    ad_slot_id INTEGER NOT NULL,
    gross_remainder_milli_points INTEGER NOT NULL DEFAULT 0,
    publisher_share_remainder_numerator INTEGER NOT NULL DEFAULT 0,
    impression_count INTEGER NOT NULL DEFAULT 0,
    billed_points INTEGER NOT NULL DEFAULT 0,
    publisher_points INTEGER NOT NULL DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (gross_remainder_milli_points BETWEEN 0 AND 999),
    CHECK (publisher_share_remainder_numerator BETWEEN 0 AND 9999999),
    CHECK (publisher_points <= billed_points)
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE cpm_billing_event_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key CHAR(64) NOT NULL UNIQUE,
    event_type VARCHAR(32) NOT NULL,
    event_id VARCHAR(160) NOT NULL,
    decision_id VARCHAR(160) NOT NULL,
    stream_key CHAR(64) NOT NULL,
    accumulator_id INTEGER NULL,
    advertiser_organization_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    publisher_organization_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL,
    ad_slot_id INTEGER NOT NULL,
    revenue_share_rule_id INTEGER NULL,
    revenue_share_rule_key VARCHAR(64) NOT NULL,
    share_ratio_bps INTEGER NOT NULL,
    bid_points_per_thousand INTEGER NOT NULL,
    assessed_gross_points INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    reason VARCHAR(120) NULL,
    gross_remainder_before INTEGER NOT NULL DEFAULT 0,
    gross_points INTEGER NOT NULL DEFAULT 0,
    gross_remainder_after INTEGER NOT NULL DEFAULT 0,
    publisher_share_remainder_before INTEGER NOT NULL DEFAULT 0,
    publisher_points INTEGER NOT NULL DEFAULT 0,
    publisher_share_remainder_after INTEGER NOT NULL DEFAULT 0,
    platform_points INTEGER NOT NULL DEFAULT 0,
    advertiser_ledger_entry_id INTEGER NULL,
    publisher_ledger_entry_id INTEGER NULL,
    reservation_id VARCHAR(160) NULL,
    occurred_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (event_type = 'impression'),
    CHECK (share_ratio_bps BETWEEN 0 AND 10000),
    CHECK (bid_points_per_thousand > 0),
    CHECK (status IN ('processing', 'accrued', 'billed', 'skipped')),
    CHECK (gross_remainder_before BETWEEN 0 AND 999),
    CHECK (gross_remainder_after BETWEEN 0 AND 999),
    CHECK (publisher_share_remainder_before BETWEEN 0 AND 9999999),
    CHECK (publisher_share_remainder_after BETWEEN 0 AND 9999999),
    CHECK (gross_points = publisher_points + platform_points),
    CHECK (assessed_gross_points >= gross_points),
    CHECK ((status = 'processing' AND processed_at IS NULL) OR (status <> 'processing' AND processed_at IS NOT NULL)),
    CHECK (
        status <> 'processing'
        OR (
            assessed_gross_points = 0
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    ),
    CHECK (
        status <> 'accrued'
        OR (
            assessed_gross_points = 0
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    ),
    CHECK (
        status <> 'billed'
        OR (
            (gross_points > 0 OR publisher_points > 0)
            AND assessed_gross_points = gross_points
            AND reason IS NULL
            AND (
                (gross_points = 0 AND advertiser_ledger_entry_id IS NULL AND reservation_id IS NULL)
                OR (gross_points > 0 AND advertiser_ledger_entry_id IS NOT NULL AND reservation_id IS NOT NULL)
            )
            AND (
                (publisher_points = 0 AND publisher_ledger_entry_id IS NULL)
                OR (publisher_points > 0 AND publisher_ledger_entry_id IS NOT NULL)
            )
        )
    ),
    CHECK (
        status <> 'skipped'
        OR (
            reason IS NOT NULL
            AND gross_points = 0
            AND publisher_points = 0
            AND platform_points = 0
            AND gross_remainder_before = gross_remainder_after
            AND publisher_share_remainder_before = publisher_share_remainder_after
            AND advertiser_ledger_entry_id IS NULL
            AND publisher_ledger_entry_id IS NULL
            AND reservation_id IS NULL
        )
    )
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE withdrawal_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    requested_by_user_id INTEGER NOT NULL,
    points_amount INTEGER NOT NULL,
    amount_cny TEXT NOT NULL,
    points_per_cny INTEGER NOT NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    review_status VARCHAR(32) NOT NULL,
    payment_status VARCHAR(32) NOT NULL,
    payout_method VARCHAR(64) NOT NULL,
    payout_account_json TEXT NOT NULL,
    applicant_notes TEXT NULL,
    reviewer_user_id INTEGER NULL,
    reviewer_notes TEXT NULL,
    payment_proof_id INTEGER NULL,
    payment_completed_by_user_id INTEGER NULL,
    payment_notes TEXT NULL,
    ledger_entry_id INTEGER NOT NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejected_at DATETIME NULL,
    revoked_at DATETIME NULL,
    resubmitted_at DATETIME NULL,
    UNIQUE (id, organization_id),
    UNIQUE (organization_id, idempotency_key),
    UNIQUE (ledger_entry_id),
    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CHECK (points_amount > 0),
    CHECK (points_per_cny = 100),
    CHECK (CAST(amount_cny AS NUMERIC) = CAST(points_amount AS NUMERIC) / 100.0),
    CHECK (length(trim(idempotency_key)) BETWEEN 1 AND 160),
    CHECK (length(payout_method) BETWEEN 1 AND 64),
    CHECK (length(payout_account_json) <= 8192),
    CHECK (applicant_notes IS NULL OR length(applicant_notes) <= 2000),
    CHECK (reviewer_notes IS NULL OR length(reviewer_notes) <= 2000),
    CHECK (payment_notes IS NULL OR length(payment_notes) <= 2000),
    CHECK (review_status IN ('pending', 'approved', 'rejected', 'revoked')),
    CHECK (payment_status IN ('not_started', 'pending', 'paid')),
    CHECK (
        (review_status = 'pending' AND payment_status = 'not_started' AND reviewer_user_id IS NULL AND reviewer_notes IS NULL AND reviewed_at IS NULL AND approved_at IS NULL AND rejected_at IS NULL AND revoked_at IS NULL AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL)
        OR (review_status = 'rejected' AND payment_status = 'not_started' AND reviewer_user_id IS NOT NULL AND reviewer_notes IS NOT NULL AND length(trim(reviewer_notes)) > 0 AND reviewed_at IS NOT NULL AND rejected_at IS NOT NULL AND approved_at IS NULL AND revoked_at IS NULL AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL)
        OR (review_status = 'revoked' AND payment_status = 'not_started' AND reviewer_user_id IS NULL AND reviewer_notes IS NULL AND reviewed_at IS NULL AND approved_at IS NULL AND rejected_at IS NULL AND revoked_at IS NOT NULL AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL)
        OR (review_status = 'approved' AND payment_status = 'pending' AND reviewer_user_id IS NOT NULL AND reviewed_at IS NOT NULL AND approved_at IS NOT NULL AND rejected_at IS NULL AND revoked_at IS NULL AND payment_proof_id IS NULL AND payment_completed_by_user_id IS NULL AND payment_notes IS NULL AND paid_at IS NULL)
        OR (review_status = 'approved' AND payment_status = 'paid' AND reviewer_user_id IS NOT NULL AND reviewed_at IS NOT NULL AND approved_at IS NOT NULL AND rejected_at IS NULL AND revoked_at IS NULL AND payment_proof_id IS NOT NULL AND payment_completed_by_user_id IS NOT NULL AND payment_notes IS NOT NULL AND length(trim(payment_notes)) > 0 AND paid_at IS NOT NULL)
    )
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE withdrawal_proofs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    withdrawal_request_id INTEGER NOT NULL,
    organization_id INTEGER NOT NULL,
    uploaded_by_user_id INTEGER NOT NULL,
    object_key VARCHAR(512) NOT NULL UNIQUE,
    content_type VARCHAR(120) NOT NULL,
    byte_size INTEGER NOT NULL,
    checksum VARCHAR(160) NULL,
    status VARCHAR(32) NOT NULL,
    verification_error_code VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verification_attempted_at DATETIME NULL,
    verified_at DATETIME NULL,
    UNIQUE (id, withdrawal_request_id, organization_id),
    FOREIGN KEY (withdrawal_request_id, organization_id) REFERENCES withdrawal_requests (id, organization_id) ON DELETE RESTRICT,
    CHECK (object_key GLOB 'withdrawals/[0-9]*/[0-9]*/*-*'),
    CHECK (content_type IN ('application/pdf', 'image/jpeg', 'image/png')),
    CHECK (byte_size BETWEEN 1 AND 10485760),
    CHECK (status IN ('pending_upload', 'verified', 'rejected')),
    CHECK (
        (status = 'pending_upload' AND checksum IS NULL AND verification_error_code IS NULL AND verification_attempted_at IS NULL AND verified_at IS NULL)
        OR (status = 'rejected' AND checksum IS NULL AND verification_error_code IS NOT NULL AND verification_attempted_at IS NOT NULL AND verified_at IS NULL)
        OR (status = 'verified' AND length(checksum) = 71 AND checksum GLOB 'sha256:[0-9a-f]*' AND verification_error_code IS NULL AND verification_attempted_at IS NOT NULL AND verified_at IS NOT NULL)
    )
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE withdrawal_audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    withdrawal_request_id INTEGER NOT NULL,
    organization_id INTEGER NOT NULL,
    actor_user_id INTEGER NULL,
    action VARCHAR(64) NOT NULL,
    from_review_status VARCHAR(32) NULL,
    to_review_status VARCHAR(32) NULL,
    from_payment_status VARCHAR(32) NULL,
    to_payment_status VARCHAR(32) NULL,
    proof_id INTEGER NULL,
    notes TEXT NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (withdrawal_request_id, organization_id) REFERENCES withdrawal_requests (id, organization_id) ON DELETE RESTRICT,
    FOREIGN KEY (proof_id, withdrawal_request_id, organization_id) REFERENCES withdrawal_proofs (id, withdrawal_request_id, organization_id) ON DELETE RESTRICT,
    CHECK (action IN ('requested', 'approved', 'paid', 'rejected', 'revoked', 'resubmitted', 'proof_upload_created', 'proof_verified', 'proof_rejected', 'proof_verification_deferred')),
    CHECK (
        (from_review_status IS NULL OR from_review_status IN ('pending', 'approved', 'rejected', 'revoked'))
        AND (to_review_status IS NULL OR to_review_status IN ('pending', 'approved', 'rejected', 'revoked'))
        AND (from_payment_status IS NULL OR from_payment_status IN ('not_started', 'pending', 'paid'))
        AND to_payment_status IN ('not_started', 'pending', 'paid')
    ),
    CHECK (
        (action = 'requested' AND from_review_status IS NULL AND from_payment_status IS NULL AND to_review_status = 'pending' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'approved' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'approved' AND to_payment_status = 'pending' AND proof_id IS NULL)
        OR (action = 'rejected' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'rejected' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'revoked' AND from_review_status = 'pending' AND from_payment_status = 'not_started' AND to_review_status = 'revoked' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'resubmitted' AND from_review_status IN ('rejected', 'revoked') AND from_payment_status = 'not_started' AND to_review_status = 'pending' AND to_payment_status = 'not_started' AND proof_id IS NULL)
        OR (action = 'paid' AND from_review_status = 'approved' AND from_payment_status = 'pending' AND to_review_status = 'approved' AND to_payment_status = 'paid' AND proof_id IS NOT NULL)
        OR (action IN ('proof_upload_created', 'proof_verified', 'proof_rejected', 'proof_verification_deferred') AND from_review_status = 'approved' AND from_payment_status = 'pending' AND to_review_status = 'approved' AND to_payment_status = 'pending' AND proof_id IS NOT NULL)
    )
)
SQL
        );

        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER withdrawal_requests_paid_proof_insert
BEFORE INSERT ON withdrawal_requests
WHEN NEW.payment_status = 'paid' AND NOT EXISTS (
    SELECT 1 FROM withdrawal_proofs
    WHERE id = NEW.payment_proof_id
      AND withdrawal_request_id = NEW.id
      AND organization_id = NEW.organization_id
      AND status = 'verified'
      AND checksum LIKE 'sha256:%'
      AND verified_at IS NOT NULL
)
BEGIN
    SELECT RAISE(ABORT, 'paid withdrawal requires a verified payment proof');
END
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER withdrawal_requests_paid_proof_update
BEFORE UPDATE ON withdrawal_requests
WHEN NEW.payment_status = 'paid' AND NOT EXISTS (
    SELECT 1 FROM withdrawal_proofs
    WHERE id = NEW.payment_proof_id
      AND withdrawal_request_id = NEW.id
      AND organization_id = NEW.organization_id
      AND status = 'verified'
      AND checksum LIKE 'sha256:%'
      AND verified_at IS NOT NULL
)
BEGIN
    SELECT RAISE(ABORT, 'paid withdrawal requires a verified payment proof');
END
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER withdrawal_proofs_preserve_paid_verification
BEFORE UPDATE ON withdrawal_proofs
WHEN OLD.status = 'verified' AND NEW.status <> 'verified' AND EXISTS (
    SELECT 1 FROM withdrawal_requests
    WHERE payment_proof_id = OLD.id
      AND id = OLD.withdrawal_request_id
      AND payment_status = 'paid'
)
BEGIN
    SELECT RAISE(ABORT, 'paid withdrawal proof verification is immutable');
END
SQL);
    }
}
