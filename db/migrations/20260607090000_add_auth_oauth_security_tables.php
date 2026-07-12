<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAuthOauthSecurityTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requested_ip VARBINARY(16) NULL,
    user_agent VARCHAR(512) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_tokens_token_hash (token_hash),
    KEY idx_password_reset_tokens_user_created (user_id, created_at),
    KEY idx_password_reset_tokens_expires_at (expires_at),
    CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE first_party_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_first_party_sessions_token_hash (session_token_hash),
    KEY idx_first_party_sessions_user_created (user_id, created_at),
    KEY idx_first_party_sessions_expires_at (expires_at),
    CONSTRAINT fk_first_party_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    client_identifier VARCHAR(120) NOT NULL,
    name VARCHAR(200) NOT NULL,
    secret_hash VARCHAR(255) NULL,
    redirect_uris_json JSON NOT NULL,
    grant_types_json JSON NOT NULL,
    scopes_json JSON NULL,
    is_confidential TINYINT(1) NOT NULL DEFAULT 1,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_clients_identifier (client_identifier),
    KEY idx_oauth_clients_organization (organization_id),
    KEY idx_oauth_clients_owner_user (owner_user_id),
    CONSTRAINT fk_oauth_clients_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_clients_owner_user FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_identifier VARCHAR(160) NOT NULL,
    description VARCHAR(255) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_scopes_identifier (scope_identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_client_scopes (
    client_id BIGINT UNSIGNED NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, scope_id),
    CONSTRAINT fk_oauth_client_scopes_client FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_client_scopes_scope FOREIGN KEY (scope_id) REFERENCES oauth_scopes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_authorization_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    code_identifier CHAR(80) NOT NULL,
    redirect_uri VARCHAR(1024) NOT NULL,
    scopes_json JSON NULL,
    code_challenge VARCHAR(255) NULL,
    code_challenge_method VARCHAR(16) NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_authorization_codes_identifier (code_identifier),
    KEY idx_oauth_authorization_codes_client_user (client_id, user_id),
    KEY idx_oauth_authorization_codes_user (user_id),
    KEY idx_oauth_authorization_codes_organization (organization_id),
    CONSTRAINT fk_oauth_authorization_codes_client FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_authorization_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_authorization_codes_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    organization_id BIGINT UNSIGNED NULL,
    authorization_code_id BIGINT UNSIGNED NULL,
    access_token_identifier CHAR(80) NOT NULL,
    scopes_json JSON NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_access_tokens_identifier (access_token_identifier),
    KEY idx_oauth_access_tokens_client_user (client_id, user_id),
    KEY idx_oauth_access_tokens_user (user_id),
    KEY idx_oauth_access_tokens_organization (organization_id),
    KEY idx_oauth_access_tokens_authorization_code (authorization_code_id),
    CONSTRAINT fk_oauth_access_tokens_client FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_access_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_access_tokens_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_oauth_access_tokens_authorization_code FOREIGN KEY (authorization_code_id) REFERENCES oauth_authorization_codes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_refresh_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    access_token_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    refresh_token_identifier CHAR(80) NOT NULL,
    family_identifier CHAR(64) NOT NULL,
    previous_refresh_token_id BIGINT UNSIGNED NULL,
    rotated_to_refresh_token_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    rotated_at DATETIME NULL,
    reuse_detected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_refresh_tokens_identifier (refresh_token_identifier),
    KEY idx_oauth_refresh_tokens_access_token (access_token_id),
    KEY idx_oauth_refresh_tokens_client_user (client_id, user_id),
    KEY idx_oauth_refresh_tokens_family (family_identifier),
    KEY idx_oauth_refresh_tokens_previous (previous_refresh_token_id),
    KEY idx_oauth_refresh_tokens_rotated_to (rotated_to_refresh_token_id),
    CONSTRAINT fk_oauth_refresh_tokens_access_token FOREIGN KEY (access_token_id) REFERENCES oauth_access_tokens (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_refresh_tokens_client FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_refresh_tokens_previous FOREIGN KEY (previous_refresh_token_id) REFERENCES oauth_refresh_tokens (id) ON DELETE SET NULL,
    CONSTRAINT fk_oauth_refresh_tokens_rotated_to FOREIGN KEY (rotated_to_refresh_token_id) REFERENCES oauth_refresh_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE oauth_user_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NULL,
    scopes_json JSON NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_user_consents_client_user_org (client_id, user_id, organization_id),
    KEY idx_oauth_user_consents_user (user_id),
    KEY idx_oauth_user_consents_organization (organization_id),
    CONSTRAINT fk_oauth_user_consents_client FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_user_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_user_consents_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE rate_limit_counters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rate_limit_key VARCHAR(255) NOT NULL,
    subject_type VARCHAR(64) NOT NULL,
    subject_id BIGINT UNSIGNED NULL,
    window_starts_at DATETIME NOT NULL,
    window_ends_at DATETIME NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_limit_counters_key_window (rate_limit_key, window_starts_at),
    KEY idx_rate_limit_counters_subject_window (subject_type, subject_id, window_starts_at),
    KEY idx_rate_limit_counters_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS rate_limit_counters');
        $this->execute('DROP TABLE IF EXISTS oauth_user_consents');
        $this->execute('DROP TABLE IF EXISTS oauth_refresh_tokens');
        $this->execute('DROP TABLE IF EXISTS oauth_access_tokens');
        $this->execute('DROP TABLE IF EXISTS oauth_authorization_codes');
        $this->execute('DROP TABLE IF EXISTS oauth_client_scopes');
        $this->execute('DROP TABLE IF EXISTS oauth_scopes');
        $this->execute('DROP TABLE IF EXISTS oauth_clients');
        $this->execute('DROP TABLE IF EXISTS first_party_sessions');
        $this->execute('DROP TABLE IF EXISTS password_reset_tokens');
    }
}
