<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;

final readonly class NoOpCronJob implements CronJobInterface
{
    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function run(): CronJobResult
    {
        return CronJobResult::completed($this->name, [
            'processed' => 0,
            'noop' => true,
        ], 'No-op job placeholder is registered and lock protected.');
    }
}
