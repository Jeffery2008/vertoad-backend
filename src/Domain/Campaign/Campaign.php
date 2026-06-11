<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

use DateTimeImmutable;
use VertoAD\Domain\Budget\CampaignBudgetCaps;

final readonly class Campaign
{
    public function __construct(
        public ?int $id,
        public int $organizationId,
        public string $name,
        public CampaignStatus $status,
        public PricingModel $pricingModel,
        public int $bidPoints,
        public string $landingUrl,
        public int $creativeAssetId,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public CampaignTargeting $targeting,
        public ?CampaignBudgetCaps $budget,
        public ?string $pauseReason = null,
    ) {
    }
}
