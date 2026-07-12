<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

use VertoAD\Domain\Campaign\CampaignTimeWindow;

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
        public string $assetType = 'html_placeholder',
        public string $assetObjectKey = '',
        public string $assetContentType = '',
        public string $snapshotPngObjectKey = '',
        public string $snapshotWebpObjectKey = '',
        public string $thumbnailWebpObjectKey = '',
        public string $assetUrl = '',
        public string $snapshotPngUrl = '',
        public string $snapshotWebpUrl = '',
        public string $thumbnailWebpUrl = '',
        public int $qualityScore = 100,
        public int $historicalCtrPerMille = 0,
        public ?int $hourlyFrequencyCap = null,
        public ?int $dailyFrequencyCap = null,
        public ?int $hourlyClickCap = null,
        public ?int $dailyClickCap = null,
        /** @var list<string> */
        public array $geos = [],
        /** @var list<string> */
        public array $devices = [],
        /** @var list<CampaignTimeWindow> */
        public array $timeWindows = [],
    ) {
    }
}
