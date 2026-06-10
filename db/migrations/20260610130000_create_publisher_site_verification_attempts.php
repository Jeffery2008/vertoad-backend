<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePublisherSiteVerificationAttempts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE publisher_site_verification_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    method VARCHAR(32) NOT NULL,
    expected_value VARCHAR(255) NOT NULL,
    observed_summary VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL,
    failure_reason VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_site_verification_attempts_site_checked (site_id, checked_at),
    KEY idx_site_verification_attempts_org_status (organization_id, status, created_at),
    CONSTRAINT fk_site_verification_attempts_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_site_verification_attempts_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_site_verification_attempts_method CHECK (method IN ('html_meta', 'dns_txt', 'verification_file')),
    CONSTRAINT chk_site_verification_attempts_status CHECK (status IN ('success', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS publisher_site_verification_attempts');
    }
}
