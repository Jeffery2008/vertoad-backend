<?php

declare(strict_types=1);

namespace VertoAD\Domain\Cron;

final readonly class CronJobResult
{
    /**
     * @param array<string, int|string|bool|null> $metrics
     */
    public function __construct(
        public string $jobName,
        public string $status,
        public bool $acquiredLock,
        public array $metrics = [],
        public ?string $message = null,
    ) {
    }

    /**
     * @param array<string, int|string|bool|null> $metrics
     */
    public static function completed(string $jobName, array $metrics = [], ?string $message = null): self
    {
        return new self($jobName, 'completed', true, $metrics, $message);
    }

    public static function locked(string $jobName): self
    {
        return new self($jobName, 'locked', false, [], 'Job lock is already held.');
    }

    public static function notFound(string $jobName): self
    {
        return new self($jobName, 'not_found', false, [], 'Cron job is not registered.');
    }
}
