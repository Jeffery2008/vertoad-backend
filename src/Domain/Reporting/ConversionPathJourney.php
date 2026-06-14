<?php

declare(strict_types=1);

namespace VertoAD\Domain\Reporting;

use DateTimeImmutable;

final readonly class ConversionPathJourney
{
    /**
     * @param list<ConversionPathTouchpoint> $touchpoints
     */
    public function __construct(
        public string $conversionEventId,
        public string $conversionId,
        public string $conversionName,
        public string $source,
        public int $valuePoints,
        public DateTimeImmutable $occurredAt,
        public int $windowSeconds,
        public string $clickEventId,
        public string $viewerId,
        public ?int $campaignId,
        public ?int $advertiserOrganizationId,
        public ?int $publisherOrganizationId,
        public array $touchpoints,
    ) {
        foreach ([
            'conversion_event_id' => $conversionEventId,
            'conversion_id' => $conversionId,
            'conversion_name' => $conversionName,
            'source' => $source,
            'click_event_id' => $clickEventId,
            'viewer_id' => $viewerId,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException($name . ' is required.');
            }
        }

        if ($valuePoints < 0) {
            throw new \InvalidArgumentException('conversion value_points cannot be negative.');
        }

        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('conversion window_seconds must be positive.');
        }

        foreach ([
            'campaign_id' => $campaignId,
            'advertiser_organization_id' => $advertiserOrganizationId,
            'publisher_organization_id' => $publisherOrganizationId,
        ] as $name => $value) {
            if ($value !== null && $value <= 0) {
                throw new \InvalidArgumentException($name . ' must be positive when present.');
            }
        }

        foreach ($touchpoints as $index => $touchpoint) {
            if (!$touchpoint instanceof ConversionPathTouchpoint) {
                throw new \InvalidArgumentException('touchpoints must contain ConversionPathTouchpoint instances.');
            }

            if ($touchpoint->position !== $index + 1) {
                throw new \InvalidArgumentException('touchpoints must use contiguous positions.');
            }
        }
    }
}
