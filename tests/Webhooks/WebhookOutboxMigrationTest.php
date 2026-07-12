<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use PHPUnit\Framework\TestCase;

final class WebhookOutboxMigrationTest extends TestCase
{
    public function testMigrationCreatesDurableOutboxAndAllBusinessEventTriggers(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20260712090000_create_webhook_business_outbox.php',
        );
        $sql = strtolower($migration);

        foreach ([
            'create table webhook_outbox_events',
            'unique key uq_webhook_outbox_events_event (event_id)',
            'idx_webhook_outbox_events_claim (status, available_at, lease_expires_at, id)',
            'api_version varchar(32) not null',
            "status in ('pending', 'processing', 'failed', 'dispatched')",
            'create trigger trg_webhook_review_decision_outbox',
            'after insert on review_decisions',
            'create trigger trg_webhook_ledger_entry_outbox',
            'after insert on ledger_entries',
            'create trigger trg_webhook_campaign_created_outbox',
            'after insert on campaigns',
            'create trigger trg_webhook_campaign_status_outbox',
            'after update on campaigns',
            'if not (new.status <=> old.status)',
            'create trigger trg_webhook_conversion_outbox',
            'after insert on attribution_conversions',
            'create trigger trg_webhook_withdrawal_status_outbox',
            'after insert on withdrawal_audit_events',
            'create trigger trg_webhook_api_client_outbox',
            'after insert on audit_logs',
            "'review.approved'",
            "'review.rejected'",
            "'billing.points_changed'",
            "'campaign.status_changed'",
            "'conversion.received'",
            "'withdrawal.status_changed'",
            "'api_client.created'",
            "'api_client.secret_rotated'",
            "left(nullif(@vertoad_request_id, ''), 160)",
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql, $fragment);
        }

        self::assertStringContainsString(
            "new.action in ('requested', 'approved', 'paid', 'rejected', 'revoked', 'resubmitted')",
            $sql,
        );
        self::assertStringNotContainsString("'proof_verified', 'withdrawal.status_changed'", $sql);
        self::assertSame(7, substr_count($sql, "'2026-07-12'"));
    }

    public function testRollbackDropsEveryTriggerBeforeTheOutboxTable(): void
    {
        $migration = strtolower((string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20260712090000_create_webhook_business_outbox.php',
        ));

        foreach ([
            'trg_webhook_api_client_outbox',
            'trg_webhook_withdrawal_status_outbox',
            'trg_webhook_conversion_outbox',
            'trg_webhook_campaign_status_outbox',
            'trg_webhook_campaign_created_outbox',
            'trg_webhook_ledger_entry_outbox',
            'trg_webhook_review_decision_outbox',
        ] as $trigger) {
            $dropPosition = strpos($migration, 'drop trigger if exists ' . $trigger);
            self::assertNotFalse($dropPosition, $trigger);
            self::assertLessThan(strpos($migration, 'drop table if exists webhook_outbox_events'), $dropPosition);
        }
    }
}
