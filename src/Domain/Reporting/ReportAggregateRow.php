<?php

declare(strict_types=1);

namespace VertoAD\Domain\Reporting;

final readonly class ReportAggregateRow
{
    public function __construct(
        public string $date,
        public ?int $organizationId,
        public ?int $campaignId,
        public ?int $siteId,
        public ?int $slotId,
        public ?string $geo,
        public ?string $device,
        public ?string $browser,
        public ?string $resolution,
        public ?string $riskBucket,
        public int $impressions,
        public int $clicks,
        public int $spendPoints,
        public int $revenuePoints,
    ) {
        if ($date === '') {
            throw new \InvalidArgumentException('Report aggregate date is required.');
        }

        foreach ([
            'organization_id' => $organizationId,
            'campaign_id' => $campaignId,
            'site_id' => $siteId,
            'slot_id' => $slotId,
        ] as $name => $value) {
            if ($value !== null && $value <= 0) {
                throw new \InvalidArgumentException($name . ' must be positive when present.');
            }
        }

        foreach ([
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend_points' => $spendPoints,
            'revenue_points' => $revenuePoints,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException($name . ' cannot be negative.');
            }
        }
    }
}
