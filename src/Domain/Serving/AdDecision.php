<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

use DateTimeImmutable;

final readonly class AdDecision
{
    public function __construct(
        public string $decisionId,
        public int $siteId,
        public int $slotId,
        public string $viewerId,
        public bool $filled,
        public ?string $reason,
        public string $iframeHtml,
        public int $width,
        public int $height,
        public ?string $adId,
        public ?int $campaignId,
        public ?int $advertiserOrganizationId,
        public ?int $publisherOrganizationId,
        public ?int $impressionCostPoints,
        public ?int $clickCostPoints,
        public ?string $landingUrl,
        public DateTimeImmutable $decidedAt,
        public ?string $requestId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $geoCode = null,
    ) {
    }

    public function withLandingUrl(string $landingUrl): self
    {
        return new self(
            decisionId: $this->decisionId,
            siteId: $this->siteId,
            slotId: $this->slotId,
            viewerId: $this->viewerId,
            filled: $this->filled,
            reason: $this->reason,
            iframeHtml: $this->iframeHtml,
            width: $this->width,
            height: $this->height,
            adId: $this->adId,
            campaignId: $this->campaignId,
            advertiserOrganizationId: $this->advertiserOrganizationId,
            publisherOrganizationId: $this->publisherOrganizationId,
            impressionCostPoints: $this->impressionCostPoints,
            clickCostPoints: $this->clickCostPoints,
            landingUrl: $landingUrl,
            decidedAt: $this->decidedAt,
            requestId: $this->requestId,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            geoCode: $this->geoCode,
        );
    }
}
