<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

interface ServingRiskAssessorInterface
{
    public function assess(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision;
}
