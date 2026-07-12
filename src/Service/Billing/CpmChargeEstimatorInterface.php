<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

interface CpmChargeEstimatorInterface
{
    public function nextChargePoints(
        int $advertiserOrganizationId,
        int $campaignId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $bidPointsPerThousand,
    ): int;
}
