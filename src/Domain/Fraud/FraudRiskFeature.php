<?php

declare(strict_types=1);

namespace VertoAD\Domain\Fraud;

final readonly class FraudRiskFeature
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public string $scopeType,
        public string $scopeId,
        public \DateTimeImmutable $windowStart,
        public \DateTimeImmutable $windowEnd,
        public ?int $siteId,
        public ?int $slotId,
        public ?string $viewerId,
        public int $impressions,
        public int $clicks,
        public int $invalidClicks,
        public int $ctrPerMille,
        public int $invalidClickRatePerMille,
        public int $riskScore,
        public string $riskBucket,
        public array $reasons,
        public \DateTimeImmutable $computedAt,
    ) {
    }
}
