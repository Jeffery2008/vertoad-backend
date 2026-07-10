<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseBackupJobRepository;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;

final class BackupJobRepositoryTest extends TestCase
{
    public function testDatabaseRepositoryPersistsListsClaimsAndFindsLatestJobs(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseBackupJobRepository($connection);
        $first = $this->job('backup_first', 'backup', 'queued', '2026-07-10T01:00:00Z');
        $second = $this->job('backup_second', 'backup', 'queued', '2026-07-10T02:00:00Z');
        $restore = $this->job('restore_first', 'restore', 'completed', '2026-07-10T03:00:00Z', 'backup_first');

        self::assertSame($first, $repository->save($first));
        $repository->save($second);
        $repository->save($restore);
        self::assertSame('backup_first', $repository->find(' backup_first ')?->jobId);
        self::assertNull($repository->find('missing'));

        $all = $repository->list(null, 2, 1);
        self::assertSame(3, $all['total']);
        self::assertSame(['backup_second', 'backup_first'], array_map(
            static fn (BackupJob $job): string => $job->jobId,
            $all['items'],
        ));
        self::assertSame(2, $repository->list('backup', 10, 0)['total']);

        $started = new DateTimeImmutable('2026-07-10T04:00:00Z');
        $claimed = $repository->claimNext('backup', $started);
        self::assertSame('backup_first', $claimed?->jobId);
        self::assertSame('running', $claimed?->status);
        self::assertSame($started->format('Y-m-d H:i:s'), $claimed?->startedAt?->format('Y-m-d H:i:s'));

        $repository->save($this->job(
            'backup_first',
            'backup',
            'completed',
            '2026-07-10T01:00:00Z',
            completedAt: '2026-07-10T05:00:00Z',
        ));
        self::assertSame('backup_first', $repository->latestCompleted('backup')?->jobId);
        self::assertSame('restore_first', $repository->latestCompleted('restore')?->jobId);
        self::assertNull($repository->latestCompleted('missing'));
        self::assertSame('backup_second', $repository->claimNext('backup', $started)?->jobId);
        self::assertNull($repository->claimNext('backup', $started));
    }

    public function testDatabaseClaimRetriesWhenConditionalUpdateLosesRace(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseBackupJobRepository($connection);
        for ($index = 1; $index <= 5; ++$index) {
            $repository->save($this->job(
                'backup_race_' . $index,
                'backup',
                'queued',
                sprintf('2026-07-10T01:00:0%dZ', $index),
            ));
        }
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER operation_backup_jobs_lose_claim
BEFORE UPDATE OF status ON operation_backup_jobs
WHEN OLD.job_id LIKE 'backup_race_%' AND OLD.status = 'queued' AND NEW.status = 'running'
BEGIN
    UPDATE operation_backup_jobs SET status = 'running' WHERE job_id = OLD.job_id;
    SELECT RAISE(IGNORE);
END
SQL);

        self::assertNull($repository->claimNext('backup', new DateTimeImmutable('2026-07-10T02:00:00Z')));
        for ($index = 1; $index <= 5; ++$index) {
            self::assertSame('running', $repository->find('backup_race_' . $index)?->status);
        }
    }

    public function testInMemoryRepositoryImplementsFilteringOrderingAndClaiming(): void
    {
        $repository = new InMemoryBackupJobRepository();
        self::assertNull($repository->find('missing'));
        self::assertNull($repository->claimNext('backup', new DateTimeImmutable()));
        self::assertNull($repository->latestCompleted('backup'));

        $repository->save($this->job('backup_a', 'backup', 'queued', '2026-07-10T01:00:00Z'));
        $repository->save($this->job('backup_b', 'backup', 'completed', '2026-07-10T02:00:00Z', completedAt: '2026-07-10T04:00:00Z'));
        $repository->save($this->job('restore_a', 'restore', 'completed', '2026-07-10T03:00:00Z', 'backup_b', '2026-07-10T05:00:00Z'));

        self::assertSame(['restore_a', 'backup_b'], array_map(
            static fn (BackupJob $job): string => $job->jobId,
            $repository->list(null, 2, 0)['items'],
        ));
        self::assertSame(2, $repository->list('backup', 10, 0)['total']);
        self::assertSame('backup_a', $repository->claimNext('backup', new DateTimeImmutable('2026-07-10T06:00:00Z'))?->jobId);
        self::assertSame('backup_b', $repository->latestCompleted('backup')?->jobId);
        self::assertSame('restore_a', $repository->latestCompleted('restore')?->jobId);
    }

    public function testDatabaseRepositoryRejectsConcurrentActiveRestoreForSameBackup(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(<<<'SQL'
CREATE UNIQUE INDEX uq_operation_backup_jobs_active_restore
ON operation_backup_jobs (source_backup_id)
WHERE job_type = 'restore' AND status IN ('queued', 'running')
SQL);
        $repository = new DatabaseBackupJobRepository($connection);
        $repository->save($this->job(
            'restore_first',
            'restore',
            'queued',
            '2026-07-10T01:00:00Z',
            'backup_source',
        ));

        try {
            $repository->save($this->job(
                'restore_second',
                'restore',
                'queued',
                '2026-07-10T02:00:00Z',
                'backup_source',
            ));
            self::fail('Expected active restore uniqueness conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('restore_already_queued', $exception->getMessage());
        }

        $repository->save($this->job(
            'restore_first',
            'restore',
            'failed',
            '2026-07-10T01:00:00Z',
            'backup_source',
            '2026-07-10T01:10:00Z',
        ));
        self::assertSame(
            'restore_second',
            $repository->save($this->job(
                'restore_second',
                'restore',
                'queued',
                '2026-07-10T02:00:00Z',
                'backup_source',
            ))->jobId,
        );
    }

    public function testDatabaseRepositoryDoesNotTranslateUnrelatedUniqueConstraintFailures(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'CREATE UNIQUE INDEX uq_operation_backup_jobs_environment ON operation_backup_jobs (environment)',
        );
        $repository = new DatabaseBackupJobRepository($connection);
        $repository->save($this->job('backup_first', 'backup', 'queued', '2026-07-10T01:00:00Z'));

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($this->job('backup_second', 'backup', 'queued', '2026-07-10T02:00:00Z'));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE operation_backup_jobs (
    job_id VARCHAR(64) PRIMARY KEY,
    job_type VARCHAR(16),
    source_backup_id VARCHAR(64) NULL,
    status VARCHAR(16),
    requested_by_user_id INTEGER,
    request_id VARCHAR(160),
    environment VARCHAR(32),
    reason VARCHAR(500) NULL,
    manifest_object_key VARCHAR(1024) NULL,
    manifest_sha256 VARCHAR(64) NULL,
    mysql_object_key VARCHAR(1024) NULL,
    mysql_sha256 VARCHAR(64) NULL,
    config_object_key VARCHAR(1024) NULL,
    evidence_object_key VARCHAR(1024) NULL,
    object_count INTEGER,
    byte_count INTEGER,
    error_message TEXT NULL,
    created_at VARCHAR(32),
    started_at VARCHAR(32) NULL,
    completed_at VARCHAR(32) NULL
)
SQL);

        return $connection;
    }

    private function job(
        string $id,
        string $type,
        string $status,
        string $createdAt,
        ?string $sourceBackupId = null,
        ?string $completedAt = null,
    ): BackupJob {
        return new BackupJob(
            jobId: $id,
            jobType: $type,
            sourceBackupId: $sourceBackupId,
            status: $status,
            requestedByUserId: 7,
            requestId: 'req-' . $id,
            environment: 'staging',
            reason: $type === 'restore' ? 'Restore drill reason' : null,
            manifestObjectKey: $status === 'completed' ? 'backups/' . $id . '/manifest.json' : null,
            manifestSha256: $status === 'completed' ? str_repeat('b', 64) : null,
            mysqlObjectKey: $status === 'completed' ? 'backups/' . $id . '/mysql.sql' : null,
            mysqlSha256: $status === 'completed' ? str_repeat('a', 64) : null,
            configObjectKey: $status === 'completed' ? 'backups/' . $id . '/configuration.json' : null,
            evidenceObjectKey: $type === 'restore' && $status === 'completed' ? 'evidence/' . $id . '.json' : null,
            objectCount: $status === 'completed' ? 3 : 0,
            byteCount: $status === 'completed' ? 1200 : 0,
            errorMessage: $status === 'failed' ? 'failure' : null,
            createdAt: new DateTimeImmutable($createdAt),
            startedAt: $status === 'queued' ? null : new DateTimeImmutable($createdAt),
            completedAt: $completedAt === null ? null : new DateTimeImmutable($completedAt),
        );
    }
}
