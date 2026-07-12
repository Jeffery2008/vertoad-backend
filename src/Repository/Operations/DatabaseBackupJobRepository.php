<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;

final readonly class DatabaseBackupJobRepository implements BackupJobRepositoryInterface
{
    private const CLAIM_RETRIES = 5;

    /**
     * @param Closure():string|null $leaseOwnerGenerator
     * @param Closure():DateTimeImmutable|null $clock
     */
    public function __construct(
        private Connection $connection,
        private int $leaseDurationSeconds = 1800,
        private ?Closure $leaseOwnerGenerator = null,
        private ?Closure $clock = null,
    ) {
        if ($this->leaseDurationSeconds <= 0) {
            throw new InvalidArgumentException('Backup job lease duration must be positive.');
        }
    }

    public function save(BackupJob $job): BackupJob
    {
        try {
            $current = $this->find($job->jobId);
            if (!$current instanceof BackupJob) {
                $this->connection->insert('operation_backup_jobs', $this->row($job));

                return $job;
            }

            return $this->saveWithLease($current, $job);
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->isActiveRestoreConflict($job, $exception)) {
                throw new RuntimeException('restore_already_queued', previous: $exception);
            }

            throw $exception;
        }
    }

    public function find(string $jobId): ?BackupJob
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_backup_jobs')
            ->where('job_id = :job_id')
            ->setParameter('job_id', trim($jobId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function list(?string $jobType, int $limit, int $offset): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_backup_jobs')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('job_id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('operation_backup_jobs');
        if ($jobType !== null) {
            $query->where('job_type = :job_type')->setParameter('job_type', $jobType);
            $count->where('job_type = :job_type')->setParameter('job_type', $jobType);
        }

        return [
            'items' => array_map(fn (array $row): BackupJob => $this->hydrate($row), $query->fetchAllAssociative()),
            'total' => (int) $count->fetchOne(),
        ];
    }

    public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob
    {
        $claimedAt = $this->formatDate($startedAt);
        for ($attempt = 0; $attempt < self::CLAIM_RETRIES; ++$attempt) {
            $jobId = $this->connection->createQueryBuilder()
                ->select('job_id')
                ->from('operation_backup_jobs')
                ->where('job_type = :job_type')
                ->andWhere(<<<'SQL'
(status = :queued_status OR (
    status = :running_status
    AND lease_expires_at IS NOT NULL
    AND lease_expires_at <= :claimed_at
))
SQL)
                ->setParameter('job_type', $jobType)
                ->setParameter('queued_status', 'queued')
                ->setParameter('running_status', 'running')
                ->setParameter('claimed_at', $claimedAt)
                ->orderBy('created_at', 'ASC')
                ->addOrderBy('job_id', 'ASC')
                ->setMaxResults(1)
                ->fetchOne();
            if ($jobId === false) {
                return null;
            }

            $leaseOwner = $this->newLeaseOwner();
            $leaseExpiresAt = $startedAt->modify('+' . $this->leaseDurationSeconds . ' seconds');
            $affected = $this->connection->createQueryBuilder()
                ->update('operation_backup_jobs')
                ->set('status', ':new_status')
                ->set('started_at', 'COALESCE(started_at, :started_at)')
                ->set('completed_at', ':completed_at')
                ->set('lease_owner', ':lease_owner')
                ->set('lease_expires_at', ':lease_expires_at')
                ->set('attempt_count', 'attempt_count + 1')
                ->set('heartbeat_at', ':heartbeat_at')
                ->where('job_id = :job_id')
                ->andWhere(<<<'SQL'
(status = :queued_status OR (
    status = :running_status
    AND lease_expires_at IS NOT NULL
    AND lease_expires_at <= :claimed_at
))
SQL)
                ->setParameter('new_status', 'running')
                ->setParameter('started_at', $claimedAt)
                ->setParameter('completed_at', null)
                ->setParameter('lease_owner', $leaseOwner)
                ->setParameter('lease_expires_at', $this->formatDate($leaseExpiresAt))
                ->setParameter('heartbeat_at', $claimedAt)
                ->setParameter('job_id', (string) $jobId)
                ->setParameter('queued_status', 'queued')
                ->setParameter('running_status', 'running')
                ->setParameter('claimed_at', $claimedAt)
                ->executeStatement();
            if ($affected === 1) {
                return $this->find((string) $jobId);
            }
        }

        return null;
    }

    public function latestCompleted(string $jobType): ?BackupJob
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_backup_jobs')
            ->where('job_type = :job_type')
            ->andWhere('status = :status')
            ->setParameter('job_type', $jobType)
            ->setParameter('status', 'completed')
            ->orderBy('completed_at', 'DESC')
            ->addOrderBy('job_id', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    private function saveWithLease(BackupJob $current, BackupJob $next): BackupJob
    {
        $this->assertImmutableMetadata($current, $next);
        if (
            $current->status !== 'running'
            || $next->leaseOwner === null
            || $next->leaseOwner === ''
            || $next->attemptCount <= 0
            || $next->heartbeatAt === null
            || $next->leaseExpiresAt === null
            || !in_array($next->status, ['running', 'completed', 'failed'], true)
        ) {
            throw new RuntimeException('backup_job_lease_lost');
        }

        $transitionAt = $next->status === 'running' ? $next->heartbeatAt : $next->completedAt;
        if (
            !$transitionAt instanceof DateTimeImmutable
            || $transitionAt < $next->heartbeatAt
            || $transitionAt > $next->leaseExpiresAt
        ) {
            throw new RuntimeException('backup_job_lease_lost');
        }

        $values = $this->executionRow($next);
        $query = $this->connection->createQueryBuilder()->update('operation_backup_jobs');
        foreach (array_keys($values) as $column) {
            $query->set($column, ':' . $column)->setParameter($column, $values[$column]);
        }
        $affected = $query
            ->where('job_id = :expected_job_id')
            ->andWhere('status = :expected_status')
            ->andWhere('lease_owner = :expected_lease_owner')
            ->andWhere('attempt_count = :expected_attempt_count')
            ->andWhere('lease_expires_at > :repository_now')
            ->setParameter('expected_job_id', $next->jobId)
            ->setParameter('expected_status', 'running')
            ->setParameter('expected_lease_owner', $next->leaseOwner)
            ->setParameter('expected_attempt_count', $next->attemptCount)
            ->setParameter('repository_now', $this->formatDate($this->now()))
            ->executeStatement();
        if ($affected !== 1) {
            throw new RuntimeException('backup_job_lease_lost');
        }

        return $this->find($next->jobId) ?? throw new RuntimeException('backup_job_not_found_after_save');
    }

    private function assertImmutableMetadata(BackupJob $current, BackupJob $next): void
    {
        if (
            $current->jobType !== $next->jobType
            || $current->sourceBackupId !== $next->sourceBackupId
            || $current->requestedByUserId !== $next->requestedByUserId
            || $current->requestId !== $next->requestId
            || $current->environment !== $next->environment
            || $current->reason !== $next->reason
            || $this->formatDate($current->createdAt) !== $this->formatDate($next->createdAt)
        ) {
            throw new RuntimeException('backup_job_immutable_metadata_mismatch');
        }
    }

    private function isActiveRestoreConflict(
        BackupJob $job,
        UniqueConstraintViolationException $exception,
    ): bool {
        if ($job->jobType !== 'restore' || !in_array($job->status, ['queued', 'running'], true)) {
            return false;
        }
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'uq_operation_backup_jobs_active_restore')
            || str_contains($message, 'operation_backup_jobs.active_restore_slot');
    }

    private function newLeaseOwner(): string
    {
        $owner = $this->leaseOwnerGenerator === null
            ? bin2hex(random_bytes(32))
            : ($this->leaseOwnerGenerator)();
        $owner = trim($owner);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $owner)) {
            throw new RuntimeException('Backup job lease owner generator returned an invalid identifier.');
        }

        return $owner;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'job_id', 'job_type', 'source_backup_id', 'status', 'requested_by_user_id', 'request_id',
            'environment', 'reason', 'manifest_object_key', 'manifest_sha256', 'mysql_object_key', 'mysql_sha256',
            'config_object_key', 'evidence_object_key', 'object_count', 'byte_count', 'error_message',
            'created_at', 'started_at', 'completed_at', 'lease_owner', 'lease_expires_at', 'attempt_count',
            'heartbeat_at',
        ];
    }

    /** @return array<string, int|string|null> */
    private function row(BackupJob $job): array
    {
        return [
            'job_id' => $job->jobId,
            'job_type' => $job->jobType,
            'source_backup_id' => $job->sourceBackupId,
            'status' => $job->status,
            'requested_by_user_id' => $job->requestedByUserId,
            'request_id' => $job->requestId,
            'environment' => $job->environment,
            'reason' => $job->reason,
            'manifest_object_key' => $job->manifestObjectKey,
            'manifest_sha256' => $job->manifestSha256,
            'mysql_object_key' => $job->mysqlObjectKey,
            'mysql_sha256' => $job->mysqlSha256,
            'config_object_key' => $job->configObjectKey,
            'evidence_object_key' => $job->evidenceObjectKey,
            'object_count' => $job->objectCount,
            'byte_count' => $job->byteCount,
            'error_message' => $job->errorMessage,
            'created_at' => $this->formatDate($job->createdAt),
            'started_at' => $job->startedAt === null ? null : $this->formatDate($job->startedAt),
            'completed_at' => $job->completedAt === null ? null : $this->formatDate($job->completedAt),
            'lease_owner' => $job->leaseOwner,
            'lease_expires_at' => $job->leaseExpiresAt === null ? null : $this->formatDate($job->leaseExpiresAt),
            'attempt_count' => $job->attemptCount,
            'heartbeat_at' => $job->heartbeatAt === null ? null : $this->formatDate($job->heartbeatAt),
        ];
    }

    /** @return array<string, int|string|null> */
    private function executionRow(BackupJob $job): array
    {
        $row = $this->row($job);
        foreach ([
            'job_id', 'job_type', 'source_backup_id', 'requested_by_user_id', 'request_id',
            'environment', 'reason', 'created_at',
        ] as $immutable) {
            unset($row[$immutable]);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BackupJob
    {
        return new BackupJob(
            jobId: (string) $row['job_id'],
            jobType: (string) $row['job_type'],
            sourceBackupId: $row['source_backup_id'] === null ? null : (string) $row['source_backup_id'],
            status: (string) $row['status'],
            requestedByUserId: (int) $row['requested_by_user_id'],
            requestId: (string) $row['request_id'],
            environment: (string) $row['environment'],
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            manifestObjectKey: $row['manifest_object_key'] === null ? null : (string) $row['manifest_object_key'],
            manifestSha256: $row['manifest_sha256'] === null ? null : (string) $row['manifest_sha256'],
            mysqlObjectKey: $row['mysql_object_key'] === null ? null : (string) $row['mysql_object_key'],
            mysqlSha256: $row['mysql_sha256'] === null ? null : (string) $row['mysql_sha256'],
            configObjectKey: $row['config_object_key'] === null ? null : (string) $row['config_object_key'],
            evidenceObjectKey: $row['evidence_object_key'] === null ? null : (string) $row['evidence_object_key'],
            objectCount: (int) $row['object_count'],
            byteCount: (int) $row['byte_count'],
            errorMessage: $row['error_message'] === null ? null : (string) $row['error_message'],
            createdAt: $this->date((string) $row['created_at']),
            startedAt: $row['started_at'] === null ? null : $this->date((string) $row['started_at']),
            completedAt: $row['completed_at'] === null ? null : $this->date((string) $row['completed_at']),
            leaseOwner: $row['lease_owner'] === null ? null : (string) $row['lease_owner'],
            leaseExpiresAt: $row['lease_expires_at'] === null ? null : $this->date((string) $row['lease_expires_at']),
            attemptCount: (int) $row['attempt_count'],
            heartbeatAt: $row['heartbeat_at'] === null ? null : $this->date((string) $row['heartbeat_at']),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable() : ($this->clock)();
    }
}
