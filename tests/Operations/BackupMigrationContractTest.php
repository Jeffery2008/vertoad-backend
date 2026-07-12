<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Install\PhinxMigrationRunner;

final class BackupMigrationContractTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root === null || !is_dir($this->root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testMigrationDefinesMysqlLeaseAndGlobalRestoreContracts(): void
    {
        $migration = strtolower((string) file_get_contents($this->migrationPath()));

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
            'lease_owner varchar(128) null',
            'lease_expires_at datetime null',
            'attempt_count int unsigned not null default 0',
            'heartbeat_at datetime null',
            'idx_operation_backup_jobs_queue (job_type, status, lease_expires_at, created_at)',
            'active_restore_slot tinyint unsigned generated always as',
            "when job_type = 'restore' and status in ('queued', 'running') then 1",
            'unique key uq_operation_backup_jobs_active_restore (active_restore_slot)',
            'chk_operation_backup_jobs_running_lease',
            "job_type in ('backup', 'restore')",
            "status in ('queued', 'running', 'completed', 'failed')",
        ] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        self::assertStringNotContainsString('active_restore_source_backup_id', $migration);
        self::assertStringContainsString('drop table if exists operation_backup_jobs', $migration);
    }

    public function testMigrationRunsOnSqliteWithLeaseCheckAndGlobalRestoreMutex(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-backup-migration-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/db/migrations', 0700, true);
        mkdir($this->root . '/db/seeds', 0700, true);
        copy($this->migrationPath(), $this->root . '/db/migrations/20260710020000_create_operation_backup_jobs.php');
        $databasePath = $this->root . '/backup-contract';

        (new PhinxMigrationRunner($this->root))->migrate([
            'driver' => 'pdo_sqlite',
            'path' => $databasePath,
        ]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $databasePath . '.sqlite3',
        ]);

        $columns = $connection->fetchFirstColumn('SELECT name FROM pragma_table_xinfo(\'operation_backup_jobs\')');
        self::assertContains('lease_owner', $columns);
        self::assertContains('lease_expires_at', $columns);
        self::assertContains('attempt_count', $columns);
        self::assertContains('heartbeat_at', $columns);
        self::assertContains('active_restore_slot', $columns);
        $indexes = $connection->fetchFirstColumn('SELECT name FROM pragma_index_list(\'operation_backup_jobs\')');
        self::assertContains('uq_operation_backup_jobs_active_restore', $indexes);
        self::assertContains('idx_operation_backup_jobs_queue', $indexes);

        $connection->insert('operation_backup_jobs', $this->row('restore_one', 'backup_one'));
        try {
            $connection->insert('operation_backup_jobs', $this->row('restore_two', 'backup_two'));
            self::fail('Expected globally active restore uniqueness conflict.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertTrue(true);
        }
        $connection->update('operation_backup_jobs', ['status' => 'failed'], ['job_id' => 'restore_one']);
        $connection->insert('operation_backup_jobs', $this->row('restore_two', 'backup_two'));

        $running = $this->row('restore_running', 'backup_three');
        $running['status'] = 'running';
        $connection->update('operation_backup_jobs', ['status' => 'failed'], ['job_id' => 'restore_two']);
        try {
            $connection->insert('operation_backup_jobs', $running);
            self::fail('Expected running jobs without a durable lease to violate the schema contract.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertTrue(true);
        }
        $connection->close();
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 2) . '/db/migrations/20260710020000_create_operation_backup_jobs.php';
    }

    /** @return array<string, int|string|null> */
    private function row(string $jobId, string $sourceBackupId): array
    {
        return [
            'job_id' => $jobId,
            'job_type' => 'restore',
            'source_backup_id' => $sourceBackupId,
            'status' => 'queued',
            'requested_by_user_id' => 7,
            'request_id' => 'req-' . $jobId,
            'environment' => 'staging',
            'reason' => 'Scheduled restore contract drill',
            'object_count' => 0,
            'byte_count' => 0,
            'created_at' => '2026-07-10 10:00:00',
        ];
    }
}
