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

        $lockKey = $this->lockKey($jobName);
        if (!$this->locks->acquire($lockKey, $this->defaultLockTtlSeconds)) {
            return CronJobResult::locked($jobName);
        }

        try {
            return $job->run();
        } finally {
            $this->locks->release($lockKey);
        }
    }

    private function lockKey(string $jobName): string
    {
        return 'cron:lock:' . $jobName;
    }
}
