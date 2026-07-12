<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\DatabaseBackupJobRepository;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;

final class BackupJobRepositoryTest extends TestCase
{
    private DateTimeImmutable $now;
    private int $ownerSequence = 0;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-07-10T04:00:00Z');
        $this->ownerSequence = 0;
    }

    public function testDatabaseRepositoryPersistsListsClaimsAndFindsLatestJobs(): void
    {
        $connection = $this->connection();
        $repository = $this->repository($connection);
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

        $claimed = $repository->claimNext('backup', $this->now);
        self::assertSame('backup_first', $claimed?->jobId);
        self::assertSame('running', $claimed?->status);
        self::assertSame('worker-1', $claimed?->leaseOwner);
        self::assertSame(1, $claimed?->attemptCount);
        self::assertEquals($this->now, $claimed?->heartbeatAt);
        self::assertEquals($this->now->modify('+30 minutes'), $claimed?->leaseExpiresAt);

        self::assertInstanceOf(BackupJob::class, $claimed);
        $this->now = new DateTimeImmutable('2026-07-10T04:05:00Z');
        $repository->save($this->transition($claimed, 'completed', $this->now));
        self::assertSame('backup_first', $repository->latestCompleted('backup')?->jobId);
        self::assertSame('restore_first', $repository->latestCompleted('restore')?->jobId);
        self::assertNull($repository->latestCompleted('missing'));
        self::assertSame('backup_second', $repository->claimNext('backup', $this->now)?->jobId);
        self::assertNull($repository->claimNext('backup', $this->now));
    }

    public function testClaimAtomicallyReclaimsStaleRunningAndFencesTheOldWorker(): void
    {
        $connection = $this->connection();
        $repository = $this->repository($connection, 60);
        $repository->save($this->job('backup_stale', 'backup', 'queued', '2026-07-10T03:00:00Z'));
        $first = $repository->claimNext('backup', $this->now);
        self::assertInstanceOf(BackupJob::class, $first);

        $this->now = new DateTimeImmutable('2026-07-10T04:00:30Z');
        self::assertNull($repository->claimNext('backup', $this->now));

        $this->now = new DateTimeImmutable('2026-07-10T04:01:00Z');
        try {
            $repository->save($this->transition($first, 'completed', $this->now));
            self::fail('An expired worker must not complete before another worker reclaims the job.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_lease_lost', $exception->getMessage());
        }

        $second = $repository->claimNext('backup', $this->now);
        self::assertInstanceOf(BackupJob::class, $second);
        self::assertSame('worker-2', $second->leaseOwner);
        self::assertSame(2, $second->attemptCount);
        self::assertEquals($first->startedAt, $second->startedAt);
        self::assertSame($first->requestedByUserId, $second->requestedByUserId);
        self::assertSame($first->requestId, $second->requestId);
        self::assertSame($first->environment, $second->environment);
        self::assertEquals($first->createdAt, $second->createdAt);

        try {
            $repository->save($this->transition($first, 'failed', $this->now));
            self::fail('The reclaimed worker must fence every old-worker terminal write.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_lease_lost', $exception->getMessage());
        }

        $tampered = $this->transition($second, 'running', $this->now, requestId: 'req-tampered');
        try {
            $repository->save($tampered);
            self::fail('Worker writes must not alter immutable audit metadata.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_immutable_metadata_mismatch', $exception->getMessage());
        }
        self::assertSame('req-backup_stale', $repository->find('backup_stale')?->requestId);

        $this->now = new DateTimeImmutable('2026-07-10T04:01:15Z');
        $completed = $repository->save($this->transition($second, 'completed', $this->now));
        self::assertSame('completed', $completed->status);
        self::assertSame('worker-2', $completed->leaseOwner);
        self::assertSame(2, $completed->attemptCount);

        try {
            $repository->save($this->transition($first, 'failed', $this->now));
            self::fail('A terminal job must remain immutable to an old worker.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_lease_lost', $exception->getMessage());
        }
    }

    public function testHeartbeatExtendsLeaseAndPreventsPrematureReclaim(): void
    {
        $connection = $this->connection();
        $repository = $this->repository($connection, 60);
        $repository->save($this->job('backup_heartbeat', 'backup', 'queued', '2026-07-10T03:00:00Z'));
        $claimed = $repository->claimNext('backup', $this->now);
        self::assertInstanceOf(BackupJob::class, $claimed);
        self::assertInstanceOf(DateTimeImmutable::class, $claimed->heartbeatAt);
        try {
            $repository->save($this->transition(
                $claimed,
                'completed',
                $claimed->heartbeatAt->modify('-1 second'),
            ));
            self::fail('A terminal transition cannot predate the persisted heartbeat.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_lease_lost', $exception->getMessage());
        }

        $this->now = new DateTimeImmutable('2026-07-10T04:00:50Z');
        $renewed = $repository->save($this->transition($claimed, 'running', $this->now));
        self::assertEquals(new DateTimeImmutable('2026-07-10T04:01:50Z'), $renewed->leaseExpiresAt);

        $this->now = new DateTimeImmutable('2026-07-10T04:01:01Z');
        self::assertNull($repository->claimNext('backup', $this->now));
        $this->now = new DateTimeImmutable('2026-07-10T04:01:51Z');
        self::assertSame(2, $repository->claimNext('backup', $this->now)?->attemptCount);
    }

    public function testDatabaseClaimRetriesWhenConditionalUpdateLosesRace(): void
    {
        $connection = $this->connection();
        $repository = $this->repository($connection);
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

        self::assertNull($repository->claimNext('backup', $this->now));
        for ($index = 1; $index <= 5; ++$index) {
            self::assertSame('running', $repository->find('backup_race_' . $index)?->status);
        }
    }

    public function testDatabaseRepositoryEnforcesOneGlobalActiveRestoreAcrossSources(): void
    {
        $connection = $this->connection();
        $repository = $this->repository($connection);
        $repository->save($this->job(
            'restore_first',
            'restore',
            'queued',
            '2026-07-10T01:00:00Z',
            'backup_source_one',
        ));

        try {
            $repository->save($this->job(
                'restore_second',
                'restore',
                'queued',
                '2026-07-10T02:00:00Z',
                'backup_source_two',
            ));
            self::fail('Expected a global active restore uniqueness conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('restore_already_queued', $exception->getMessage());
        }

        $claimed = $repository->claimNext('restore', $this->now);
        self::assertInstanceOf(BackupJob::class, $claimed);
        $this->now = new DateTimeImmutable('2026-07-10T04:01:00Z');
        $repository->save($this->transition($claimed, 'failed', $this->now));
        self::assertSame(
            'restore_second',
            $repository->save($this->job(
                'restore_second',
                'restore',
                'queued',
                '2026-07-10T02:00:00Z',
                'backup_source_two',
            ))->jobId,
        );
    }

    public function testDatabaseRepositoryDoesNotTranslateUnrelatedUniqueConstraintFailures(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'CREATE UNIQUE INDEX uq_operation_backup_jobs_environment ON operation_backup_jobs (environment)',
        );
        $repository = $this->repository($connection);
        $repository->save($this->job('backup_first', 'backup', 'queued', '2026-07-10T01:00:00Z'));

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($this->job('backup_second', 'backup', 'queued', '2026-07-10T02:00:00Z'));
    }

    public function testRepositoryValidatesLeaseConfigurationAndGeneratedOwners(): void
    {
        try {
            new DatabaseBackupJobRepository($this->connection(), 0);
            self::fail('Expected an invalid lease duration.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('duration', $exception->getMessage());
        }

        $connection = $this->connection();
        $invalid = new DatabaseBackupJobRepository(
            $connection,
            leaseOwnerGenerator: static fn (): string => 'invalid owner',
            clock: fn (): DateTimeImmutable => $this->now,
        );
        $invalid->save($this->job('backup_invalid_owner', 'backup', 'queued', '2026-07-10T01:00:00Z'));
        try {
            $invalid->claimNext('backup', $this->now);
            self::fail('Expected an invalid generated lease owner.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid identifier', $exception->getMessage());
        }

        $randomConnection = $this->connection();
        $random = new DatabaseBackupJobRepository(
            $randomConnection,
            clock: fn (): DateTimeImmutable => $this->now,
        );
        $random->save($this->job('backup_random_owner', 'backup', 'queued', '2026-07-10T01:00:00Z'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $random->claimNext('backup', $this->now)?->leaseOwner);
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

    private function repository(Connection $connection, int $leaseDurationSeconds = 1800): DatabaseBackupJobRepository
    {
        return new DatabaseBackupJobRepository(
            $connection,
            $leaseDurationSeconds,
            function (): string {
                return 'worker-' . ++$this->ownerSequence;
            },
            fn (): DateTimeImmutable => $this->now,
        );
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
    completed_at VARCHAR(32) NULL,
    lease_owner VARCHAR(128) NULL,
    lease_expires_at VARCHAR(32) NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    heartbeat_at VARCHAR(32) NULL,
    active_restore_slot INTEGER GENERATED ALWAYS AS (
        CASE WHEN job_type = 'restore' AND status IN ('queued', 'running') THEN 1 ELSE NULL END
    ) STORED
)
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE UNIQUE INDEX uq_operation_backup_jobs_active_restore
ON operation_backup_jobs (active_restore_slot)
SQL);

        return $connection;
    }

    private function transition(
        BackupJob $job,
        string $status,
        DateTimeImmutable $at,
        ?string $requestId = null,
    ): BackupJob {
        $leaseDuration = $job->leaseExpiresAt !== null && $job->heartbeatAt !== null
            ? $job->leaseExpiresAt->getTimestamp() - $job->heartbeatAt->getTimestamp()
            : 0;
        $running = $status === 'running';

        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: $job->sourceBackupId,
            status: $status,
            requestedByUserId: $job->requestedByUserId,
            requestId: $requestId ?? $job->requestId,
            environment: $job->environment,
            reason: $job->reason,
            manifestObjectKey: $status === 'completed' && $job->jobType === 'backup'
                ? 'backups/' . $job->jobId . '/manifest.json'
                : $job->manifestObjectKey,
            manifestSha256: $status === 'completed' && $job->jobType === 'backup'
                ? str_repeat('b', 64)
                : $job->manifestSha256,
            mysqlObjectKey: $status === 'completed' && $job->jobType === 'backup'
                ? 'backups/' . $job->jobId . '/mysql.sql'
                : $job->mysqlObjectKey,
            mysqlSha256: $status === 'completed' && $job->jobType === 'backup'
                ? str_repeat('a', 64)
                : $job->mysqlSha256,
            configObjectKey: $status === 'completed' && $job->jobType === 'backup'
                ? 'backups/' . $job->jobId . '/configuration.json'
                : $job->configObjectKey,
            evidenceObjectKey: $job->evidenceObjectKey,
            objectCount: $status === 'completed' ? 3 : $job->objectCount,
            byteCount: $status === 'completed' ? 1200 : $job->byteCount,
            errorMessage: $status === 'failed' ? 'failure' : $job->errorMessage,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $running ? null : $at,
            leaseOwner: $job->leaseOwner,
            leaseExpiresAt: $running ? $at->modify('+' . $leaseDuration . ' seconds') : $job->leaseExpiresAt,
            attemptCount: $job->attemptCount,
            heartbeatAt: $running ? $at : $job->heartbeatAt,
        );
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
