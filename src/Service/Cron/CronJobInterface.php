<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;

interface CronJobInterface
{
    public function name(): string;

    public function run(): CronJobResult;
}
