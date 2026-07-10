<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Operations\Backup\BackupExecutor;

final readonly class BackupCreateJob implements CronJobInterface
{
    public function __construct(private BackupExecutor $executor)
    {
    }

    public function name(): string
    {
        return 'backup-create';
    }

    public function run(): CronJobResult
    {
        $result = $this->executor->executeNextBackup();
        $metrics = [
            'job_id' => $result->jobId,
            'jobs_processed' => $result->jobId === null ? 0 : 1,
            'object_count' => $result->objectCount,
            'byte_count' => $result->byteCount,
        ];

        return $result->status === 'failed'
            ? CronJobResult::failed($this->name(), $metrics, $result->errorMessage)
            : CronJobResult::completed($this->name(), $metrics, $result->status === 'idle' ? 'No backup job is queued.' : null);
    }
}
