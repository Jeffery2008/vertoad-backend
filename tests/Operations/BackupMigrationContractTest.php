<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class BackupMigrationContractTest extends TestCase
{
    public function testMigrationDefinesDurableBackupAndRestoreQueue(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260710020000_create_operation_backup_jobs.php';
        $migration = strtolower((string) file_get_contents($path));

        self::assertStringContainsString('create table operation_backup_jobs', $migration);
        foreach ([
            'job_id varchar(64) not null',
            'job_type varchar(16) not null',
            'source_backup_id varchar(64) null',
            'request_id varchar(160) not null',
            'manifest_object_key varchar(1024) null',
            'manifest_sha256 char(64) null',
            'mysql_sha256 char(64) null',
            'evidence_object_key varchar(1024) null',
            'idx_operation_backup_jobs_queue',
            'active_restore_source_backup_id varchar(64) generated always as',
            'unique key uq_operation_backup_jobs_active_restore',
            "job_type in ('backup', 'restore')",
            "status in ('queued', 'running', 'completed', 'failed')",
        ] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        self::assertStringContainsString('drop table if exists operation_backup_jobs', $migration);
    }
}
