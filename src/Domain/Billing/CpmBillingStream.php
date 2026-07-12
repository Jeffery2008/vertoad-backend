<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use InvalidArgumentException;

final readonly class CpmBillingStream
{
    public function __construct(
        public int $advertiserOrganizationId,
        public int $campaignId,
        public int $publisherOrganizationId,
        public int $siteId,
        public int $adSlotId,
    ) {
        if (
            $this->advertiserOrganizationId <= 0
            || $this->campaignId <= 0
            || $this->publisherOrganizationId <= 0
            || $this->siteId <= 0
            || $this->adSlotId <= 0
        ) {
            throw new InvalidArgumentException('CPM billing stream identifiers must be positive.');
        }
    }

    /**
     * Compatibility factory for callers that still pass the event rule.
     * Rule data belongs to CpmRevenueShareSnapshot; it is deliberately not
     * part of this stream's identity or persistence key.
     */
    public static function fromRule(
        int $advertiserOrganizationId,
        int $campaignId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        ?int $revenueShareRuleId = null,
        int $shareRatioBps = 0,
    ): self {
        if ($revenueShareRuleId !== null && $revenueShareRuleId <= 0) {
            throw new InvalidArgumentException('CPM billing revenue share rule ID must be positive when provided.');
        }
        if ($shareRatioBps < 0 || $shareRatioBps > 10_000) {
            throw new InvalidArgumentException('CPM billing share ratio must be between 0 and 10000 basis points.');
        }

        return new self(
            $advertiserOrganizationId,
            $campaignId,
            $publisherOrganizationId,
            $siteId,
            $adSlotId,
        );
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->advertiserOrganizationId,
            (string) $this->campaignId,
            (string) $this->publisherOrganizationId,
            (string) $this->siteId,
            (string) $this->adSlotId,
        ]));
    }
}
