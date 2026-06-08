<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

use InvalidArgumentException;

final readonly class CampaignBudgetCaps
{
    public function __construct(
        public int $campaignId,
        public int $organizationId,
        public ?int $totalCapPoints,
        public ?int $dailyCapPoints,
        public ?int $hourlyCapPoints,
    ) {
        if ($this->campaignId <= 0) {
            throw new InvalidArgumentException('Campaign budget campaign ID must be positive.');
        }

        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('Campaign budget organization ID must be positive.');
        }

        foreach ([
            'total' => $this->totalCapPoints,
            'daily' => $this->dailyCapPoints,
            'hourly' => $this->hourlyCapPoints,
        ] as $name => $points) {
            if ($points !== null && $points <= 0) {
                throw new InvalidArgumentException(sprintf('Campaign %s budget cap must be positive when provided.', $name));
            }
        }
    }
}
