<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWebhookBusinessOutbox extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE webhook_outbox_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(160) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    api_version VARCHAR(32) NOT NULL,
    aggregate_type VARCHAR(80) NOT NULL,
    aggregate_id VARCHAR(160) NOT NULL,
    data_json JSON NOT NULL,
    request_id VARCHAR(160) NULL,
    occurred_at DATETIME(6) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    lease_token VARCHAR(160) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    dispatched_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_outbox_events_event (event_id),
    KEY idx_webhook_outbox_events_claim (status, available_at, lease_expires_at, id),
    KEY idx_webhook_outbox_events_org_type (organization_id, event_type, id),
    KEY idx_webhook_outbox_events_request (request_id, id),
    CONSTRAINT chk_webhook_outbox_events_status CHECK (status IN ('pending', 'processing', 'failed', 'dispatched')),
    CONSTRAINT chk_webhook_outbox_events_lease CHECK (
        (status = 'processing' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)
        OR (status <> 'processing' AND lease_token IS NULL AND lease_expires_at IS NULL)
    ),
    CONSTRAINT chk_webhook_outbox_events_dispatch CHECK (
        (status = 'dispatched' AND dispatched_at IS NOT NULL)
        OR (status <> 'dispatched' AND dispatched_at IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Transactional business-event outbox for at-least-once webhook fan-out.'
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_review_decision_outbox
AFTER INSERT ON review_decisions
FOR EACH ROW
INSERT INTO webhook_outbox_events (
    event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
    data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
) VALUES (
    CONCAT('evt_review_decision_', NEW.id),
    NEW.organization_id,
    IF(NEW.decision = 'approved', 'review.approved', 'review.rejected'),
    '2026-07-12',
    'creative_review',
    CAST(NEW.review_id AS CHAR),
    JSON_OBJECT(
        'review_id', NEW.review_id,
        'asset_id', NEW.asset_id,
        'decision', NEW.decision,
        'reason', NEW.reason,
        'from_status', NEW.from_status,
        'to_status', NEW.to_status,
        'decided_by_user_id', NEW.actor_user_id
    ),
    LEFT(NULLIF(@vertoad_request_id, ''), 160),
    NEW.created_at,
    'pending',
    0,
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6)
)
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_ledger_entry_outbox
AFTER INSERT ON ledger_entries
FOR EACH ROW
INSERT INTO webhook_outbox_events (
    event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
    data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
) VALUES (
    CONCAT('evt_ledger_entry_', NEW.id),
    NEW.organization_id,
    'billing.points_changed',
    '2026-07-12',
    'ledger_entry',
    CAST(NEW.id AS CHAR),
    JSON_OBJECT(
        'ledger_entry_id', NEW.id,
        'account_type', NEW.account_type,
        'account_id', NEW.account_id,
        'points_amount', NEW.points_amount,
        'direction', NEW.direction,
        'delta_points', IF(NEW.direction = 'credit', NEW.points_amount, -NEW.points_amount),
        'balance_after_points', NEW.balance_after_points,
        'reference_type', NEW.reference_type,
        'reference_id', NEW.reference_id,
        'memo', NEW.memo,
        'metadata', JSON_EXTRACT(NEW.metadata_json, '$')
    ),
    LEFT(NULLIF(@vertoad_request_id, ''), 160),
    NEW.created_at,
    'pending',
    0,
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6)
)
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_campaign_created_outbox
AFTER INSERT ON campaigns
FOR EACH ROW
INSERT INTO webhook_outbox_events (
    event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
    data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
) VALUES (
    CONCAT('evt_campaign_created_', NEW.id),
    NEW.organization_id,
    'campaign.status_changed',
    '2026-07-12',
    'campaign',
    CAST(NEW.id AS CHAR),
    JSON_OBJECT(
        'campaign_id', NEW.id,
        'previous_status', NULL,
        'status', NEW.status,
        'pause_reason', NEW.pause_reason
    ),
    LEFT(NULLIF(@vertoad_request_id, ''), 160),
    NEW.created_at,
    'pending',
    0,
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6)
)
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_campaign_status_outbox
AFTER UPDATE ON campaigns
FOR EACH ROW
BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO webhook_outbox_events (
            event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
            data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
        ) VALUES (
            CONCAT('evt_campaign_', NEW.id, '_', REPLACE(UUID(), '-', '')),
            NEW.organization_id,
            'campaign.status_changed',
            '2026-07-12',
            'campaign',
            CAST(NEW.id AS CHAR),
            JSON_OBJECT(
                'campaign_id', NEW.id,
                'previous_status', OLD.status,
                'status', NEW.status,
                'pause_reason', NEW.pause_reason
            ),
            LEFT(NULLIF(@vertoad_request_id, ''), 160),
            UTC_TIMESTAMP(6),
            'pending',
            0,
            UTC_TIMESTAMP(6),
            UTC_TIMESTAMP(6)
        );
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_conversion_outbox
AFTER INSERT ON attribution_conversions
FOR EACH ROW
BEGIN
    DECLARE webhook_organization_id BIGINT UNSIGNED;

    SET webhook_organization_id = NEW.organization_id;
    IF webhook_organization_id IS NULL AND NEW.decision_id IS NOT NULL THEN
        SET webhook_organization_id = (
            SELECT advertiser_organization_id
            FROM ad_serving_decisions
            WHERE decision_id = NEW.decision_id
            LIMIT 1
        );
    END IF;
    IF webhook_organization_id IS NULL AND NEW.campaign_id IS NOT NULL THEN
        SET webhook_organization_id = (
            SELECT organization_id
            FROM campaigns
            WHERE id = NEW.campaign_id
            LIMIT 1
        );
    END IF;

    IF webhook_organization_id IS NOT NULL THEN
        INSERT INTO webhook_outbox_events (
            event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
            data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
        ) VALUES (
            CONCAT('evt_conversion_', NEW.conversion_id),
            webhook_organization_id,
            'conversion.received',
            '2026-07-12',
            'conversion',
            NEW.conversion_id,
            JSON_OBJECT(
                'conversion_id', NEW.conversion_id,
                'source_event_id', NEW.event_id,
                'source', NEW.source,
                'conversion_name', NEW.conversion_name,
                'value_points', NEW.value_points,
                'attributed', IF(NEW.attributed = 1, TRUE, FALSE),
                'click_event_id', NEW.click_event_id,
                'decision_id', NEW.decision_id,
                'campaign_id', NEW.campaign_id,
                'occurred_at', DATE_FORMAT(NEW.occurred_at, '%Y-%m-%dT%H:%i:%sZ')
            ),
            LEFT(NULLIF(@vertoad_request_id, ''), 160),
            NEW.created_at,
            'pending',
            0,
            UTC_TIMESTAMP(6),
            UTC_TIMESTAMP(6)
        );
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_withdrawal_status_outbox
AFTER INSERT ON withdrawal_audit_events
FOR EACH ROW
BEGIN
    IF NEW.action IN ('requested', 'approved', 'paid', 'rejected', 'revoked', 'resubmitted') THEN
        INSERT INTO webhook_outbox_events (
            event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
            data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
        ) VALUES (
            CONCAT('evt_withdrawal_audit_', NEW.id),
            NEW.organization_id,
            'withdrawal.status_changed',
            '2026-07-12',
            'withdrawal_request',
            CAST(NEW.withdrawal_request_id AS CHAR),
            JSON_OBJECT(
                'withdrawal_request_id', NEW.withdrawal_request_id,
                'action', NEW.action,
                'previous_review_status', NEW.from_review_status,
                'review_status', NEW.to_review_status,
                'previous_payment_status', NEW.from_payment_status,
                'payment_status', NEW.to_payment_status,
                'proof_id', NEW.proof_id,
                'actor_user_id', NEW.actor_user_id
            ),
            LEFT(NULLIF(@vertoad_request_id, ''), 160),
            NEW.created_at,
            'pending',
            0,
            UTC_TIMESTAMP(6),
            UTC_TIMESTAMP(6)
        );
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_webhook_api_client_outbox
AFTER INSERT ON audit_logs
FOR EACH ROW
BEGIN
    IF NEW.organization_id IS NOT NULL
        AND NEW.action IN ('sdk.oauth_client.create', 'sdk.oauth_client.rotate_secret') THEN
        INSERT INTO webhook_outbox_events (
            event_id, organization_id, event_type, api_version, aggregate_type, aggregate_id,
            data_json, request_id, occurred_at, status, attempt_count, available_at, created_at
        ) VALUES (
            CONCAT('evt_oauth_client_audit_', NEW.id),
            NEW.organization_id,
            IF(NEW.action = 'sdk.oauth_client.create', 'api_client.created', 'api_client.secret_rotated'),
            '2026-07-12',
            'oauth_client',
            COALESCE(CAST(NEW.subject_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(NEW.metadata_json, '$.client_id'))),
            JSON_OBJECT(
                'oauth_client_id', NEW.subject_id,
                'client_id', JSON_UNQUOTE(JSON_EXTRACT(NEW.metadata_json, '$.client_id')),
                'name', JSON_UNQUOTE(JSON_EXTRACT(NEW.metadata_json, '$.name')),
                'is_confidential', JSON_EXTRACT(NEW.metadata_json, '$.is_confidential'),
                'grant_types', JSON_EXTRACT(NEW.metadata_json, '$.grant_types'),
                'scopes', JSON_EXTRACT(NEW.metadata_json, '$.scopes'),
                'changed_by_user_id', NEW.actor_user_id
            ),
            LEFT(NEW.request_id, 160),
            NEW.created_at,
            'pending',
            0,
            UTC_TIMESTAMP(6),
            UTC_TIMESTAMP(6)
        );
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_api_client_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_withdrawal_status_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_conversion_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_campaign_status_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_campaign_created_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_ledger_entry_outbox');
        $this->execute('DROP TRIGGER IF EXISTS trg_webhook_review_decision_outbox');
        $this->execute('DROP TABLE IF EXISTS webhook_outbox_events');
    }
}
