<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePublisherWithdrawalTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('revenue_share_rules')
            ->addColumn('scope', 'string', ['limit' => 32])
            ->addColumn('organization_id', 'integer', ['null' => true])
            ->addColumn('site_id', 'integer', ['null' => true])
            ->addColumn('ad_slot_id', 'integer', ['null' => true])
            ->addColumn('share_ratio_bps', 'integer')
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'active'])
            ->addColumn('version', 'integer')
            ->addColumn('created_by_user_id', 'integer', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['scope', 'organization_id', 'site_id', 'ad_slot_id'])
            ->addIndex(['status', 'version'])
            ->create();

        $this->table('publisher_earning_events')
            ->addColumn('event_id', 'string', ['limit' => 160])
            ->addColumn('publisher_organization_id', 'integer')
            ->addColumn('site_id', 'integer')
            ->addColumn('ad_slot_id', 'integer')
            ->addColumn('advertiser_organization_id', 'integer')
            ->addColumn('campaign_id', 'integer', ['null' => true])
            ->addColumn('gross_points', 'integer')
            ->addColumn('share_ratio_bps', 'integer')
            ->addColumn('publisher_points', 'integer')
            ->addColumn('revenue_share_rule_id', 'integer', ['null' => true])
            ->addColumn('ledger_entry_id', 'integer')
            ->addColumn('metadata_json', 'text', ['null' => true])
            ->addColumn('earned_at', 'datetime')
            ->addIndex(['event_id'], ['unique' => true])
            ->addIndex(['publisher_organization_id', 'earned_at'])
            ->create();

        $this->table('withdrawal_requests')
            ->addColumn('organization_id', 'integer')
            ->addColumn('requested_by_user_id', 'integer')
            ->addColumn('points_amount', 'integer')
            ->addColumn('status', 'string', ['limit' => 32])
            ->addColumn('payout_method', 'string', ['limit' => 64])
            ->addColumn('payout_account_json', 'text')
            ->addColumn('applicant_notes', 'text', ['null' => true])
            ->addColumn('reviewer_user_id', 'integer', ['null' => true])
            ->addColumn('reviewer_notes', 'text', ['null' => true])
            ->addColumn('ledger_entry_id', 'integer', ['null' => true])
            ->addColumn('requested_at', 'datetime')
            ->addColumn('reviewed_at', 'datetime', ['null' => true])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('rejected_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('resubmitted_at', 'datetime', ['null' => true])
            ->addIndex(['organization_id', 'status'])
            ->create();

        $this->table('withdrawal_proofs')
            ->addColumn('withdrawal_request_id', 'integer')
            ->addColumn('organization_id', 'integer')
            ->addColumn('uploaded_by_user_id', 'integer')
            ->addColumn('object_key', 'string', ['limit' => 512])
            ->addColumn('content_type', 'string', ['limit' => 120])
            ->addColumn('byte_size', 'integer')
            ->addColumn('checksum', 'string', ['limit' => 160, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 32])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('confirmed_at', 'datetime', ['null' => true])
            ->addIndex(['object_key'], ['unique' => true])
            ->addIndex(['withdrawal_request_id', 'status'])
            ->create();

        $this->table('withdrawal_audit_events')
            ->addColumn('withdrawal_request_id', 'integer')
            ->addColumn('organization_id', 'integer')
            ->addColumn('actor_user_id', 'integer', ['null' => true])
            ->addColumn('action', 'string', ['limit' => 64])
            ->addColumn('from_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('to_status', 'string', ['limit' => 32])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('metadata_json', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['withdrawal_request_id', 'created_at'])
            ->create();
    }
}
