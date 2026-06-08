<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

final class CronJobRegistry
{
    /** @var array<string, CronJobInterface> */
    private array $jobs = [];

    /**
     * @param iterable<CronJobInterface> $jobs
     */
    public function __construct(iterable $jobs)
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->name()] = $job;
        }
    }

    public function get(string $name): ?CronJobInterface
    {
        return $this->jobs[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_keys($this->jobs));
    }
}
