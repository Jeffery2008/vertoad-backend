<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BillableAdEvent
{
    public function __construct(
        public string $eventType,
        public string $eventId,
        public string $decisionId,
        public int $siteId,
        public int $slotId,
        public int $publisherOrganizationId,
        public int $advertiserOrganizationId,
        public int $campaignId,
        public string $adId,
        public string $viewerId,
        public int $costPoints,
        public bool $valid,
        public DateTimeImmutable $occurredAt,
    ) {
        if (!in_array($this->eventType, ['impression', 'click'], true)) {
            throw new InvalidArgumentException('Billable ad event type is invalid.');
        }
        if (trim($this->eventId) === '') {
            throw new InvalidArgumentException('Billable ad event id is required.');
        }
        if ($this->siteId <= 0 || $this->slotId <= 0) {
            throw new InvalidArgumentException('Billable ad event inventory identifiers must be positive.');
        }
        if ($this->publisherOrganizationId <= 0 || $this->advertiserOrganizationId <= 0 || $this->campaignId <= 0) {
            throw new InvalidArgumentException('Billable ad event organization and campaign identifiers must be positive.');
        }
        if ($this->costPoints < 0) {
            throw new InvalidArgumentException('Billable ad event cost points cannot be negative.');
        }
    }
}
