<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;

final readonly class RevenueShareRule
{
    public function __construct(
        public ?int $id,
        public string $scope,
        public ?int $organizationId,
        public ?int $siteId,
        public ?int $adSlotId,
        public int $shareRatioBps,
        public string $status,
        public int $version,
        public ?int $createdByUserId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
