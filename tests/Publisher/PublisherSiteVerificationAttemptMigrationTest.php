<?php

declare(strict_types=1);

namespace VertoAD\Tests\Publisher;

use PHPUnit\Framework\TestCase;

final class PublisherSiteVerificationAttemptMigrationTest extends TestCase
{
    public function testMigrationDefinesPublisherSiteVerificationAttemptHistory(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260610130000_create_publisher_site_verification_attempts.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table publisher_site_verification_attempts',
            'site_id bigint unsigned not null',
            'organization_id bigint unsigned not null',
            'method varchar(32) not null',
            'expected_value varchar(255) not null',
            'observed_summary varchar(255) null',
            'status varchar(32) not null',
            'failure_reason varchar(120) null',
            'created_at datetime not null default current_timestamp',
            'checked_at datetime not null',
            'key idx_site_verification_attempts_site_checked (site_id, checked_at)',
            'key idx_site_verification_attempts_org_status (organization_id, status, created_at)',
            'constraint fk_site_verification_attempts_site foreign key (site_id) references sites (id) on delete cascade',
            'constraint fk_site_verification_attempts_organization foreign key (organization_id) references organizations (id) on delete cascade',
            'constraint chk_site_verification_attempts_method check (method in (\'html_meta\', \'dns_txt\', \'verification_file\'))',
            'constraint chk_site_verification_attempts_status check (status in (\'success\', \'failed\'))',
            'drop table if exists publisher_site_verification_attempts',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }
}
