<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use DateTimeImmutable;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;

final readonly class AggregateStatisticsJob implements CronJobInterface
{
    public function __construct(
        private DatabaseReportAggregateRepository $aggregates,
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
    ) {
        if ($to <= $from) {
            throw new \InvalidArgumentException('Aggregate statistics end time must be after start time.');
        }
    }

    public function name(): string
    {
        return 'aggregate-statistics';
    }

    public function run(): CronJobResult
    {
        return CronJobResult::completed(
            $this->name(),
            $this->aggregates->refreshFromEvents($this->from, $this->to),
        );
    }
}
