<?php

declare(strict_types=1);

namespace VertoAD\Repository\Cron;

use VertoAD\Domain\Serving\AdEvent;

interface ServingEventPersistenceInterface
{
    public function persist(AdEvent $event): void;

    public function acknowledge(AdEvent $event): void;
}
