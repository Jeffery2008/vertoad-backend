<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;

final readonly class DatabaseBackupJobRepository implements BackupJobRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(BackupJob $job): BackupJob
    {
        $row = $this->row($job);
        try {
            if ($this->find($job->jobId) === null) {
                $this->connection->insert('operation_backup_jobs', $row);
            } else {
                $this->connection->update('operation_backup_jobs', $row, ['job_id' => $job->jobId]);
            }
        } catch (UniqueConstraintViolationException $exception) {
            if ($job->jobType === 'restore' && in_array($job->status, ['queued', 'running'], true)) {
                throw new RuntimeException('restore_already_queued', previous: $exception);
            }

            throw $exception;
        }

        return $job;
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
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $jobId = $this->connection->createQueryBuilder()
                ->select('job_id')
                ->from('operation_backup_jobs')
                ->where('job_type = :job_type')
                ->andWhere('status = :status')
                ->setParameter('job_type', $jobType)
                ->setParameter('status', 'queued')
                ->orderBy('created_at', 'ASC')
                ->addOrderBy('job_id', 'ASC')
                ->setMaxResults(1)
                ->fetchOne();
            if ($jobId === false) {
                return null;
            }

            $affected = $this->connection->update('operation_backup_jobs', [
                'status' => 'running',
                'started_at' => $this->formatDate($startedAt),
                'error_message' => null,
            ], [
                'job_id' => (string) $jobId,
                'status' => 'queued',
            ]);
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

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'job_id', 'job_type', 'source_backup_id', 'status', 'requested_by_user_id', 'request_id',
            'environment', 'reason', 'manifest_object_key', 'manifest_sha256', 'mysql_object_key', 'mysql_sha256',
            'config_object_key', 'evidence_object_key', 'object_count', 'byte_count', 'error_message',
            'created_at', 'started_at', 'completed_at',
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
        ];
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
}
