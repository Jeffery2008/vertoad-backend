<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use PHPUnit\Framework\TestCase;

final class AuthSchemaMigrationTest extends TestCase
{
    private string $migrationSql;

    protected function setUp(): void
    {
        $migrationPath = dirname(__DIR__, 2) . '/db/migrations/20260607090000_add_auth_oauth_security_tables.php';

        self::assertFileExists($migrationPath);

        $this->migrationSql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($migrationPath))) ?? '';
    }

    public function testAuthOauthAndSecurityTablesAreCreated(): void
    {
        foreach ([
            'password_reset_tokens',
            'first_party_sessions',
            'oauth_clients',
            'oauth_authorization_codes',
            'oauth_access_tokens',
            'oauth_refresh_tokens',
            'oauth_scopes',
            'oauth_client_scopes',
            'oauth_user_consents',
            'rate_limit_counters',
        ] as $tableName) {
            self::assertStringContainsString('create table ' . $tableName, $this->migrationSql);
        }
    }

    public function testRequiredColumnsAndUniqueIndexesArePresent(): void
    {
        foreach ([
            'user_id bigint unsigned not null',
            'token_hash char(64) not null',
            'expires_at datetime not null',
            'used_at datetime null',
            'session_token_hash char(64) not null',
            'last_seen_at datetime null',
            'organization_id bigint unsigned null',
            'client_identifier varchar(120) not null',
            'secret_hash varchar(255) null',
            'redirect_uris_json json not null',
            'grant_types_json json not null',
            'revoked_at datetime null',
            'code_identifier char(80) not null',
            'access_token_identifier char(80) not null',
            'refresh_token_identifier char(80) not null',
            'previous_refresh_token_id bigint unsigned null',
            'rotated_to_refresh_token_id bigint unsigned null',
            'reuse_detected_at datetime null',
            'scope_identifier varchar(160) not null',
            'rate_limit_key varchar(255) not null',
            'window_starts_at datetime not null',
            'attempt_count int unsigned not null default 0',
        ] as $columnSql) {
            self::assertStringContainsString($columnSql, $this->migrationSql);
        }

        foreach ([
            'unique key uq_password_reset_tokens_token_hash (token_hash)',
            'unique key uq_first_party_sessions_token_hash (session_token_hash)',
            'unique key uq_oauth_clients_identifier (client_identifier)',
            'unique key uq_oauth_authorization_codes_identifier (code_identifier)',
            'unique key uq_oauth_access_tokens_identifier (access_token_identifier)',
            'unique key uq_oauth_refresh_tokens_identifier (refresh_token_identifier)',
            'unique key uq_oauth_scopes_identifier (scope_identifier)',
            'unique key uq_rate_limit_counters_key_window (rate_limit_key, window_starts_at)',
        ] as $indexSql) {
            self::assertStringContainsString($indexSql, $this->migrationSql);
        }
    }

    public function testForeignKeysReferenceCoreAuthAndOrganizationTables(): void
    {
        foreach ([
            'foreign key (user_id) references users (id)',
            'foreign key (organization_id) references organizations (id)',
            'foreign key (client_id) references oauth_clients (id)',
            'foreign key (scope_id) references oauth_scopes (id)',
            'foreign key (authorization_code_id) references oauth_authorization_codes (id)',
            'foreign key (access_token_id) references oauth_access_tokens (id)',
            'foreign key (previous_refresh_token_id) references oauth_refresh_tokens (id)',
            'foreign key (rotated_to_refresh_token_id) references oauth_refresh_tokens (id)',
        ] as $foreignKeySql) {
            self::assertStringContainsString($foreignKeySql, $this->migrationSql);
        }
    }
}
