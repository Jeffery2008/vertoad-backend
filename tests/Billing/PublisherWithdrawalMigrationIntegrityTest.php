<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PublisherWithdrawalMigrationIntegrityTest extends TestCase
{
    public function testPublisherWithdrawalMigrationDefinesMySqlFinancialIntegrityConstraints(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260608100000_create_publisher_withdrawal_tables.php';
        self::assertFileExists($path);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/db/migrations/20260612100000_harden_publisher_withdrawal_integrity.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/db/migrations/20260710030000_productionize_withdrawal_payments.php');

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'platform_points bigint not null',
            'unique key uq_publisher_earning_events_ledger (ledger_entry_id)',
            'idempotency_key varchar(160) not null',
            'unique key uq_withdrawal_requests_org_idempotency (organization_id, idempotency_key)',
            'unique key uq_withdrawal_requests_ledger (ledger_entry_id)',
            'constraint chk_revenue_share_rules_ratio check (share_ratio_bps between 0 and 10000)',
            "constraint chk_revenue_share_rules_scope check (scope in ('global', 'publisher', 'site', 'slot'))",
            'constraint chk_revenue_share_rules_scope_target check',
            "constraint chk_revenue_share_rules_status check (status in ('active', 'inactive'))",
            'constraint fk_publisher_earning_events_ledger foreign key (ledger_entry_id) references ledger_entries (id) on delete restrict',
            'constraint fk_publisher_earning_events_rule foreign key (revenue_share_rule_id) references revenue_share_rules (id) on delete set null',
            'constraint chk_publisher_earning_events_points check (gross_points >= 0 and publisher_points >= 0 and platform_points >= 0 and gross_points = publisher_points + platform_points)',
            'ledger_entry_id bigint unsigned not null',
            'amount_cny decimal(18,2) not null',
            'points_per_cny int unsigned not null',
            'constraint fk_withdrawal_requests_ledger foreign key (ledger_entry_id) references ledger_entries (id) on delete restrict',
            'constraint chk_withdrawal_requests_points_positive check (points_amount > 0)',
            "constraint chk_withdrawal_requests_review_status check (review_status in ('pending', 'approved', 'rejected', 'revoked'))",
            "constraint chk_withdrawal_requests_payment_status check (payment_status in ('not_started', 'pending', 'paid'))",
            'constraint chk_withdrawal_requests_conversion check (points_per_cny = 100 and amount_cny = points_amount / 100)',
            'constraint chk_withdrawal_requests_state_roles check',
            'constraint fk_withdrawal_requests_payment_proof foreign key (payment_proof_id, id, organization_id) references withdrawal_proofs (id, withdrawal_request_id, organization_id) on delete restrict',
            'constraint fk_withdrawal_proofs_request_organization foreign key (withdrawal_request_id, organization_id) references withdrawal_requests (id, organization_id) on delete restrict',
            "constraint chk_withdrawal_proofs_status check (status in ('pending_upload', 'verified', 'rejected'))",
            "constraint chk_withdrawal_proofs_content_type check (content_type in ('application/pdf', 'image/jpeg', 'image/png'))",
            'constraint chk_withdrawal_proofs_byte_size check (byte_size between 1 and 10485760)',
            "checksum regexp '^sha256:[0-9a-f]{64}$'",
            'constraint fk_withdrawal_audit_events_request_organization foreign key (withdrawal_request_id, organization_id) references withdrawal_requests (id, organization_id) on delete restrict',
            'constraint fk_withdrawal_audit_events_proof_request_organization foreign key (proof_id, withdrawal_request_id, organization_id) references withdrawal_proofs (id, withdrawal_request_id, organization_id) on delete restrict',
            'constraint chk_withdrawal_audit_events_transition check',
            'create trigger trg_withdrawal_proofs_ready_insert',
            "withdrawal proof requires an approved pending payment",
            'create trigger trg_withdrawal_requests_paid_proof_update',
            'create trigger trg_withdrawal_proofs_preserve_paid_verification',
            'not (new.object_key <=> old.object_key)',
            'not (new.checksum <=> old.checksum)',
            'withdrawal proof upload identity is immutable',
            'withdrawal proof verification requires an approved pending payment',
            'paid withdrawal proof verification is immutable',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    public function testRevenueShareRulesConstrainShareRatioBasisPoints(): void
    {
        $connection = $this->createBillingConnection();

        $this->assertDatabaseRejects(static fn () => $connection->insert('revenue_share_rules', [
            'scope' => 'global',
            'share_ratio_bps' => 10001,
            'status' => 'active',
            'version' => 1,
        ]));
    }

    public function testPublisherEarningEventsConstrainPointsAndLedgerReference(): void
    {
        $connection = $this->createBillingConnection();

        $this->assertDatabaseRejects(fn () => $this->insertPublisherEarning($connection, [
            'event_id' => 'evt-negative-gross',
            'gross_points' => -1,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertPublisherEarning($connection, [
            'event_id' => 'evt-negative-publisher',
            'publisher_points' => -1,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertPublisherEarning($connection, [
            'event_id' => 'evt-negative-platform',
            'publisher_points' => 101,
            'platform_points' => -1,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertPublisherEarning($connection, [
            'event_id' => 'evt-missing-ledger',
            'ledger_entry_id' => 999,
        ]));
    }

    public function testWithdrawalRequestsConstrainAmountStateAndLedgerReference(): void
    {
        $connection = $this->createBillingConnection();

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'points_amount' => 0,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'points_per_cny' => 0,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'review_status' => 'processing',
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'payment_status' => 'paid',
            'review_status' => 'approved',
            'reviewer_user_id' => 7,
            'reviewed_at' => '2026-06-08 12:05:00',
            'approved_at' => '2026-06-08 12:05:00',
            'payment_proof_id' => 999,
            'payment_completed_by_user_id' => 7,
            'payment_notes' => 'paid',
            'paid_at' => '2026-06-08 12:10:00',
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'ledger_entry_id' => 999,
        ]));

        $this->assertDatabaseRejects(fn () => $this->insertWithdrawalRequest($connection, [
            'ledger_entry_id' => null,
        ]));
    }

    public function testWithdrawalProofsAndAuditEventsReferenceExistingWithdrawals(): void
    {
        $connection = $this->createBillingConnection();

        $this->assertDatabaseRejects(static fn () => $connection->insert('withdrawal_proofs', [
            'withdrawal_request_id' => 999,
            'organization_id' => 42,
            'uploaded_by_user_id' => 7,
            'object_key' => 'withdrawals/999/proof.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 1024,
            'status' => 'pending_upload',
        ]));

        $this->assertDatabaseRejects(static fn () => $connection->insert('withdrawal_audit_events', [
            'withdrawal_request_id' => 999,
            'organization_id' => 42,
            'actor_user_id' => 7,
            'action' => 'requested',
            'from_review_status' => null,
            'to_review_status' => 'pending',
            'from_payment_status' => null,
            'to_payment_status' => 'not_started',
        ]));
    }

    private function createBillingConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        BillingTask14Schema::create($connection);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->seedLedgerEntry($connection, id: 1, pointsAmount: 1000, direction: 'credit');

        return $connection;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPublisherEarning(Connection $connection, array $overrides): void
    {
        $connection->insert('publisher_earning_events', array_merge([
            'event_id' => 'evt-valid',
            'publisher_organization_id' => 42,
            'site_id' => 5,
            'ad_slot_id' => 10,
            'advertiser_organization_id' => 99,
            'campaign_id' => null,
            'gross_points' => 100,
            'share_ratio_bps' => 8000,
            'publisher_points' => 80,
            'platform_points' => 20,
            'revenue_share_rule_id' => null,
            'ledger_entry_id' => 1,
            'metadata_json' => null,
            'earned_at' => '2026-06-08 11:00:00',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertWithdrawalRequest(Connection $connection, array $overrides): void
    {
        $connection->insert('withdrawal_requests', array_merge([
            'organization_id' => 42,
            'requested_by_user_id' => 7,
            'points_amount' => 100,
            'amount_cny' => '1.00',
            'points_per_cny' => 100,
            'idempotency_key' => 'withdrawal:migration:' . ($overrides['id'] ?? bin2hex(random_bytes(4))),
            'review_status' => 'pending',
            'payment_status' => 'not_started',
            'payout_method' => 'bank_transfer',
            'payout_account_json' => '{"account_no":"x"}',
            'applicant_notes' => null,
            'reviewer_user_id' => null,
            'reviewer_notes' => null,
            'payment_proof_id' => null,
            'payment_completed_by_user_id' => null,
            'payment_notes' => null,
            'ledger_entry_id' => 1,
            'requested_at' => '2026-06-08 12:00:00',
            'reviewed_at' => null,
            'approved_at' => null,
            'paid_at' => null,
            'rejected_at' => null,
            'revoked_at' => null,
            'resubmitted_at' => null,
        ], $overrides));
    }

    private function seedLedgerEntry(Connection $connection, int $id, int $pointsAmount, string $direction): void
    {
        $connection->insert('ledger_entries', [
            'id' => $id,
            'organization_id' => 42,
            'account_type' => 'publisher_earnings',
            'account_id' => null,
            'points_amount' => $pointsAmount,
            'direction' => $direction,
            'balance_after_points' => $pointsAmount,
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => 'seed-ledger-' . $id,
            'memo' => null,
            'metadata_json' => null,
            'created_at' => '2026-06-08 10:00:00',
        ]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
            return;
        }

        self::fail('Expected database constraint to reject invalid billing data.');
    }
}
