<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use VertoAD\Domain\Operations\BackupJob;

interface BackupJobRepositoryInterface
{
    public function save(BackupJob $job): BackupJob;

    public function find(string $jobId): ?BackupJob;

    /**
     * @return array{items:list<BackupJob>, total:int}
     */
    public function list(?string $jobType, int $limit, int $offset): array;

    public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob;

    public function latestCompleted(string $jobType): ?BackupJob;
}
