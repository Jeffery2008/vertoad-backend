<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;

interface ServingFrequencyCapStoreInterface
{
    public function servedCount(
        int $campaignId,
        int $slotId,
        string $viewerId,
        string $window,
        DateTimeImmutable $at,
    ): int;

    public function recordServe(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $at,
    ): void;
}
