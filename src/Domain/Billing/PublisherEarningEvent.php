<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;

final readonly class PublisherEarningEvent
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public ?int $id,
        public string $eventId,
        public int $publisherOrganizationId,
        public int $siteId,
        public int $adSlotId,
        public int $advertiserOrganizationId,
        public ?int $campaignId,
        public int $grossPoints,
        public int $shareRatioBps,
        public int $publisherPoints,
        public int $platformPoints,
        public ?int $revenueShareRuleId,
        public int $ledgerEntryId,
        public ?array $metadata,
        public DateTimeImmutable $earnedAt,
    ) {
    }
}
