<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Partition\EventTablePartitionMaintainer;

final readonly class PartitionMaintenanceJob implements CronJobInterface
{
    public function __construct(private EventTablePartitionMaintainer $maintainer)
    {
    }

    public function name(): string
    {
        return 'partition-maintenance';
    }

    public function run(): CronJobResult
    {
        $metrics = $this->maintainer->maintain();

        if (($metrics['protected_tables'] ?? 0) > 0) {
            $table = (string) ($metrics['protected_table'] ?? 'unknown');

            return CronJobResult::failed(
                $this->name(),
                $metrics,
                sprintf('p_future contains rows for %s; partition maintenance was protected.', $table),
            );
        }

        return CronJobResult::completed(
            $this->name(),
            $metrics,
            'Event table partitions were maintained through the configured lookahead window.',
        );
    }
}
