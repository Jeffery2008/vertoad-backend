<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Archive\ColdQueryService;

final readonly class DuckDbColdQueryJob implements CronJobInterface
{
    public function __construct(private ColdQueryService $queries)
    {
    }

    public function name(): string
    {
        return 'duckdb-cold-query';
    }

    public function run(): CronJobResult
    {
        $job = $this->queries->runNext();
        if ($job === null) {
            return CronJobResult::completed(
                $this->name(),
                ['processed' => 0, 'queued' => 0],
                'No queued DuckDB cold query job was available.',
            );
        }

        return CronJobResult::completed(
            $this->name(),
            [
                'processed' => 1,
                'job_id' => $job->jobId,
                'row_count' => $job->rowCount,
                'scanned_objects' => count($job->scannedObjectKeys),
            ],
            'A queued DuckDB cold query job was completed.',
        );
    }
}
