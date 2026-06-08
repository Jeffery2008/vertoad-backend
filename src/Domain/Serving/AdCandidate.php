<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

final readonly class AdCandidate
{
    public function __construct(
        public string $adId,
        public int $campaignId,
        public int $advertiserOrganizationId,
        public string $creativeHtml,
        public string $landingUrl,
        public int $width,
        public int $height,
        public int $impressionCostPoints,
        public int $clickCostPoints,
    ) {
    }
}
