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
CREATE TABLE withdrawal_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    requested_by_user_id INTEGER NOT NULL,
    points_amount INTEGER NOT NULL,
    amount_cny TEXT NOT NULL,
    points_per_cny INTEGER NOT NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL,
    payout_method VARCHAR(64) NOT NULL,
    payout_account_json TEXT NOT NULL,
    applicant_notes TEXT NULL,
    reviewer_user_id INTEGER NULL,
    reviewer_notes TEXT NULL,
    ledger_entry_id INTEGER NOT NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    paid_at DATETIME NULL,
    rejected_at DATETIME NULL,
    revoked_at DATETIME NULL,
    resubmitted_at DATETIME NULL,
    UNIQUE (organization_id, idempotency_key),
    UNIQUE (ledger_entry_id),
    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
    CHECK (points_amount > 0),
    CHECK (points_per_cny > 0),
    CHECK (status IN ('requested', 'paid', 'rejected', 'revoked'))
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests (id) ON DELETE CASCADE,
    CHECK (byte_size > 0),
    CHECK (status IN ('pending_upload', 'confirmed'))
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
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    notes TEXT NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests (id) ON DELETE CASCADE,
    CHECK (action IN ('requested', 'paid', 'rejected', 'revoked', 'resubmitted')),
    CHECK (
        (from_status IS NULL OR from_status IN ('requested', 'paid', 'rejected', 'revoked'))
        AND to_status IN ('requested', 'paid', 'rejected', 'revoked')
    )
)
SQL
        );
    }
}
