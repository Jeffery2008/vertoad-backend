<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

final readonly class AllowAllServingRiskAssessor implements ServingRiskAssessorInterface
{
    public function assess(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision
    {
        return AdTrafficRiskDecision::allow();
    }
}
