<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;

final readonly class CronRunner
{
    public function __construct(
        private CronJobRegistry $registry,
        private CronLockStoreInterface $locks,
        private int $defaultLockTtlSeconds,
    ) {
    }

    public function run(string $jobName): CronJobResult
    {
        $job = $this->registry->get($jobName);
        if ($job === null) {
            return CronJobResult::notFound($jobName);
        }

        if (!$this->locks->acquire($this->lockKey($jobName), $this->defaultLockTtlSeconds)) {
            return CronJobResult::locked($jobName);
        }

        return $job->run();
    }

    private function lockKey(string $jobName): string
    {
        return 'cron:lock:' . $jobName;
    }
}
