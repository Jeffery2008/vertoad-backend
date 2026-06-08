<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

interface ServingInventoryRepositoryInterface
{
    public function isVerifiedActiveSlot(int $siteId, int $slotId): bool;

    public function publisherOrganizationIdForSlot(int $siteId, int $slotId): ?int;
}
