<?php

declare(strict_types=1);

namespace VertoAD\Repository\Cron;

use VertoAD\Domain\Serving\AdDecision;

interface ServingRequestEventBufferInterface
{
    public function recordServe(AdDecision $decision): void;
}
