<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

use DateTimeImmutable;

final readonly class AdEvent
{
    public function __construct(
        public string $eventType,
        public string $eventId,
        public string $decisionId,
        public int $siteId,
        public int $slotId,
        public string $viewerId,
        public ?string $adId,
        public ?int $campaignId,
        public ?int $advertiserOrganizationId,
        public ?int $publisherOrganizationId,
        public ?int $costPoints,
        public DateTimeImmutable $occurredAt,
        public bool $valid,
        public ?string $reason,
        public ?float $visibleRatio = null,
        public ?int $visibleMs = null,
        public ?string $requestId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $geoCode = null,
    ) {
    }
}
