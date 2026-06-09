<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Archive\ArchiveJob;

final readonly class ArchiveParquetJob implements CronJobInterface
{
    public function __construct(private ArchiveJob $archive)
    {
    }

    public function name(): string
    {
        return 'archive-parquet';
    }

    public function run(): CronJobResult
    {
        $result = $this->archive->run();

        return CronJobResult::completed(
            $this->name(),
            $result->metrics + ['archive_job' => $result->jobName],
            'Raw serving events were archived into Parquet partition manifests.',
        );
    }
}
