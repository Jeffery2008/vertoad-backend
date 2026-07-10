<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class BackupService
{
    /**
     * @param list<string> $restoreAllowedEnvironments
     * @param Closure(string):string|null $idGenerator
     * @param Closure():DateTimeImmutable|null $clock
     */
    public function __construct(
        private BackupJobRepositoryInterface $jobs,
        private AuditLogService $audit,
        private string $environment,
        private array $restoreAllowedEnvironments = ['staging'],
        private ?Closure $idGenerator = null,
        private ?Closure $clock = null,
    ) {
    }

    public function queueBackup(int $actorUserId, string $requestId): BackupJob
    {
        $this->assertActorAndRequest($actorUserId, $requestId);
        $now = $this->now();
        $job = new BackupJob(
            jobId: $this->newId('backup'),
            jobType: 'backup',
            sourceBackupId: null,
            status: 'queued',
            requestedByUserId: $actorUserId,
            requestId: trim($requestId),
            environment: $this->normalizedEnvironment(),
            reason: null,
            manifestObjectKey: null,
            manifestSha256: null,
            mysqlObjectKey: null,
            mysqlSha256: null,
            configObjectKey: null,
            evidenceObjectKey: null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: null,
            createdAt: $now,
            startedAt: null,
            completedAt: null,
        );
        $this->jobs->save($job);
        $this->audit->record(
            action: 'operations.backup.queued',
            subjectType: 'operation_backup',
            actorUserId: $actorUserId,
            requestId: trim($requestId),
            metadata: ['backup_id' => $job->jobId, 'environment' => $job->environment],
        );

        return $job;
    }

    public function queueRestore(
        string $backupId,
        string $confirmation,
        string $reason,
        int $actorUserId,
        string $requestId,
    ): BackupJob {
        $this->assertActorAndRequest($actorUserId, $requestId);
        $environment = $this->normalizedEnvironment();
        $allowed = array_map(static fn (string $value): string => strtolower(trim($value)), $this->restoreAllowedEnvironments);
        if (!in_array($environment, $allowed, true)) {
            throw new RuntimeException('Backup restore is not allowed in the current environment.');
        }

        $backupId = trim($backupId);
        $backup = $this->jobs->find($backupId);
        if (!$backup instanceof BackupJob || $backup->jobType !== 'backup') {
            throw new RuntimeException('backup_not_found');
        }
        if ($backup->status !== 'completed' || $backup->manifestObjectKey === null) {
            throw new RuntimeException('backup_not_restorable');
        }
        if ($confirmation === '' || !hash_equals($backupId, trim($confirmation))) {
            throw new InvalidArgumentException('confirmation must exactly match backup_id.');
        }
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 500) {
            throw new InvalidArgumentException('reason must contain between 10 and 500 characters.');
        }
        $existing = $this->jobs->list('restore', 1000, 0)['items'];
        foreach ($existing as $job) {
            if ($job->sourceBackupId === $backupId && in_array($job->status, ['queued', 'running'], true)) {
                throw new RuntimeException('restore_already_queued');
            }
        }

        $job = new BackupJob(
            jobId: $this->newId('restore'),
            jobType: 'restore',
            sourceBackupId: $backupId,
            status: 'queued',
            requestedByUserId: $actorUserId,
            requestId: trim($requestId),
            environment: $environment,
            reason: $reason,
            manifestObjectKey: $backup->manifestObjectKey,
            manifestSha256: $backup->manifestSha256,
            mysqlObjectKey: $backup->mysqlObjectKey,
            mysqlSha256: $backup->mysqlSha256,
            configObjectKey: $backup->configObjectKey,
            evidenceObjectKey: null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: null,
            createdAt: $this->now(),
            startedAt: null,
            completedAt: null,
        );
        $this->jobs->save($job);
        $this->audit->record(
            action: 'operations.backup.restore_queued',
            subjectType: 'operation_restore',
            actorUserId: $actorUserId,
            requestId: trim($requestId),
            metadata: [
                'backup_id' => $backupId,
                'environment' => $environment,
                'reason' => $reason,
                'restore_id' => $job->jobId,
            ],
        );

        return $job;
    }

    /**
     * @return array{items:list<array<string, mixed>>, page:array{limit:int, offset:int, total:int, has_more:bool}}
     */
    public function list(?string $jobType, int $limit = 50, int $offset = 0): array
    {
        $jobType = $jobType === null || trim($jobType) === '' ? null : strtolower(trim($jobType));
        if ($jobType !== null && !in_array($jobType, ['backup', 'restore'], true)) {
            throw new InvalidArgumentException('job_type must be backup or restore.');
        }
        if ($limit <= 0 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be non-negative.');
        }
        $result = $this->jobs->list($jobType, $limit, $offset);

        return [
            'items' => array_map($this->serialize(...), $result['items']),
            'page' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $result['total'],
                'has_more' => ($offset + count($result['items'])) < $result['total'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $jobId): array
    {
        $job = $this->jobs->find(trim($jobId));
        if (!$job instanceof BackupJob) {
            throw new RuntimeException('backup_job_not_found');
        }

        return $this->serialize($job);
    }

    /** @return array<string, mixed> */
    public function serialize(BackupJob $job): array
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
            'created_at' => $job->createdAt->format(DATE_ATOM),
            'started_at' => $job->startedAt?->format(DATE_ATOM),
            'completed_at' => $job->completedAt?->format(DATE_ATOM),
        ];
    }

    private function assertActorAndRequest(int $actorUserId, string $requestId): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Authenticated actor is required.');
        }
        if (trim($requestId) === '') {
            throw new InvalidArgumentException('request_id is required.');
        }
    }

    private function newId(string $prefix): string
    {
        $id = $this->idGenerator === null
            ? $prefix . '_' . bin2hex(random_bytes(16))
            : ($this->idGenerator)($prefix);
        $id = trim($id);
        if (!preg_match('/^[a-z][a-z0-9_]{7,63}$/', $id)) {
            throw new RuntimeException('Backup job ID generator returned an invalid identifier.');
        }

        return $id;
    }

    private function normalizedEnvironment(): string
    {
        $environment = strtolower(trim($this->environment));

        return $environment === '' ? 'production' : $environment;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable() : ($this->clock)();
    }
}
