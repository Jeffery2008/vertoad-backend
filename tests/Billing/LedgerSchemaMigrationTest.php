<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use PHPUnit\Framework\TestCase;

final class LedgerSchemaMigrationTest extends TestCase
{
    public function testCoreLedgerSchemaConstrainsAccountsAndDuplicateReversals(): void
    {
        $migrationSql = preg_replace(
            '/\s+/',
            ' ',
            strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260606134000_create_core_schema.php')),
        ) ?? '';

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
}
