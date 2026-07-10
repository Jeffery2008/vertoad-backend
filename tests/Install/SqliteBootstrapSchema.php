<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use Doctrine\DBAL\Connection;

final class SqliteBootstrapSchema
{
    public static function create(Connection $connection): void
    {
        foreach (self::statements() as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return [
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL, email_verified_at TEXT NULL)',
            'CREATE TABLE organizations (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, billing_status TEXT NOT NULL)',
            'CREATE TABLE organization_members (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL, title TEXT NULL)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, name TEXT NOT NULL, slug TEXT NOT NULL, description TEXT NULL, is_system INTEGER NOT NULL)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, description TEXT NULL)',
            'CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY (role_id, permission_id))',
            'CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL, PRIMARY KEY (user_id, role_id))',
            'CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, owner_user_id INTEGER NULL, client_identifier TEXT NOT NULL UNIQUE, name TEXT NOT NULL, secret_hash TEXT NULL, redirect_uris_json TEXT NOT NULL, grant_types_json TEXT NOT NULL, scopes_json TEXT NULL, is_confidential INTEGER NOT NULL)',
            'CREATE TABLE oauth_scopes (id INTEGER PRIMARY KEY AUTOINCREMENT, scope_identifier TEXT NOT NULL UNIQUE, description TEXT NULL, is_default INTEGER NOT NULL)',
            'CREATE TABLE oauth_client_scopes (client_id INTEGER NOT NULL, scope_id INTEGER NOT NULL, PRIMARY KEY (client_id, scope_id))',
            'CREATE TABLE system_config_versions (version_id TEXT PRIMARY KEY, config_key TEXT NOT NULL, version INTEGER NOT NULL, value_json TEXT NOT NULL, created_by_user_id INTEGER NOT NULL)',
            'CREATE TABLE revenue_share_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, organization_id INTEGER NULL, site_id INTEGER NULL, ad_slot_id INTEGER NULL, share_ratio_bps INTEGER NOT NULL, status TEXT NOT NULL, version INTEGER NOT NULL, created_by_user_id INTEGER NULL)',
            'CREATE TABLE app_installations (id INTEGER PRIMARY KEY, installation_id TEXT NOT NULL UNIQUE, admin_user_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, initial_oauth_client_id INTEGER NOT NULL, installed_at TEXT NOT NULL, installed_by_ip BLOB NULL, request_id TEXT NOT NULL, metadata_json TEXT NOT NULL)',
            'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, actor_user_id INTEGER NULL, action TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id INTEGER NULL, ip_address BLOB NULL, user_agent TEXT NULL, request_id TEXT NULL, metadata_json TEXT NULL)',
        ];
    }
}
