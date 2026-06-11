<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use PHPUnit\Framework\TestCase;

final class LedgerSchemaMigrationTest extends TestCase
{
    public function testCoreLedgerSchemaConstrainsAccountsAndDuplicateReversals(): void
    {
        $migrationSql = $this->coreMigrationSql();

        self::assertStringContainsString(
            "constraint chk_ledger_entries_account_type check (account_type in ('advertiser_balance', 'publisher_earnings'))",
            $migrationSql,
        );
        self::assertStringContainsString(
            'reversal_of_ledger_entry_id bigint unsigned generated always as',
            $migrationSql,
        );
        self::assertStringContainsString(
            "json_unquote(json_extract(metadata_json, '$.entry_kind')) = 'reversal'",
            $migrationSql,
        );
        self::assertStringContainsString(
            'unique key uq_ledger_entries_single_reversal (reversal_of_ledger_entry_id)',
            $migrationSql,
        );
    }

    public function testCoreLedgerSchemaDefinesCurrentAccountBalances(): void
    {
        $migrationSql = $this->coreMigrationSql();

        foreach ([
            'create table ledger_account_balances (',
            'organization_id bigint unsigned not null',
            'account_type varchar(64) not null',
            'balance_points bigint not null default 0',
            'updated_at datetime not null default current_timestamp on update current_timestamp',
            'primary key (organization_id, account_type)',
            'constraint fk_ledger_account_balances_organization foreign key (organization_id) references organizations (id) on delete restrict',
            "constraint chk_ledger_account_balances_account_type check (account_type in ('advertiser_balance', 'publisher_earnings'))",
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }
    }

    private function coreMigrationSql(): string
    {
        return preg_replace(
            '/\s+/',
            ' ',
            strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260606134000_create_core_schema.php')),
        ) ?? '';
    }
}
