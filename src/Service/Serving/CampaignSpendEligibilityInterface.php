<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Budget\SpendFailureReason;

interface CampaignSpendEligibilityInterface
{
    public function rejectionReason(int $organizationId, int $campaignId, int $pointsAmount, DateTimeImmutable $at): ?SpendFailureReason;
}
