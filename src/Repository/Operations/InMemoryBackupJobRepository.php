<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use VertoAD\Domain\Operations\BackupJob;

final class InMemoryBackupJobRepository implements BackupJobRepositoryInterface
{
    /** @var array<string, BackupJob> */
    private array $jobs = [];

    public function save(BackupJob $job): BackupJob
    {
        $this->jobs[$job->jobId] = $job;

        return $job;
    }

    public function find(string $jobId): ?BackupJob
    {
        return $this->jobs[trim($jobId)] ?? null;
    }

    public function list(?string $jobType, int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            $this->jobs,
            static fn (BackupJob $job): bool => $jobType === null || $job->jobType === $jobType,
        ));
        usort($items, static fn (BackupJob $left, BackupJob $right): int =>
            [$right->createdAt->format('U.u'), $right->jobId] <=> [$left->createdAt->format('U.u'), $left->jobId]);

        return [
            'items' => array_slice($items, $offset, $limit),
            'total' => count($items),
        ];
    }

    public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob
    {
        $queued = array_values(array_filter(
            $this->jobs,
            static fn (BackupJob $job): bool => $job->jobType === $jobType && $job->status === 'queued',
        ));
        usort($queued, static fn (BackupJob $left, BackupJob $right): int =>
            [$left->createdAt->format('U.u'), $left->jobId] <=> [$right->createdAt->format('U.u'), $right->jobId]);
        $job = $queued[0] ?? null;
        if (!$job instanceof BackupJob) {
            return null;
        }

        return $this->save($this->replace($job, status: 'running', startedAt: $startedAt));
    }

    public function latestCompleted(string $jobType): ?BackupJob
    {
        $completed = array_values(array_filter(
            $this->jobs,
            static fn (BackupJob $job): bool => $job->jobType === $jobType && $job->status === 'completed',
        ));
        usort($completed, static fn (BackupJob $left, BackupJob $right): int =>
            [($right->completedAt ?? $right->createdAt)->format('U.u'), $right->jobId]
            <=> [($left->completedAt ?? $left->createdAt)->format('U.u'), $left->jobId]);

        return $completed[0] ?? null;
    }

    private function replace(BackupJob $job, string $status, ?DateTimeImmutable $startedAt = null): BackupJob
    {
        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: $job->sourceBackupId,
            status: $status,
            requestedByUserId: $job->requestedByUserId,
            requestId: $job->requestId,
            environment: $job->environment,
            reason: $job->reason,
            manifestObjectKey: $job->manifestObjectKey,
            manifestSha256: $job->manifestSha256,
            mysqlObjectKey: $job->mysqlObjectKey,
            mysqlSha256: $job->mysqlSha256,
            configObjectKey: $job->configObjectKey,
            evidenceObjectKey: $job->evidenceObjectKey,
            objectCount: $job->objectCount,
            byteCount: $job->byteCount,
            errorMessage: $job->errorMessage,
            createdAt: $job->createdAt,
            startedAt: $startedAt ?? $job->startedAt,
            completedAt: $job->completedAt,
        );
    }
}
