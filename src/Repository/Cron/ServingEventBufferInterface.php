<?php

declare(strict_types=1);

namespace VertoAD\Repository\Cron;

use VertoAD\Domain\Serving\AdEvent;

interface ServingEventBufferInterface
{
    /**
     * @return list<AdEvent>
     */
    public function lease(int $limit): array;

    public function acknowledge(AdEvent $event): void;
}
