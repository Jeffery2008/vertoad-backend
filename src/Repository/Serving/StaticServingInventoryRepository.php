<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

final readonly class StaticServingInventoryRepository implements ServingInventoryRepositoryInterface
{
    /**
     * @param list<array{0:int,1:int}> $verifiedSlots
     */
    public function __construct(private array $verifiedSlots, private int $publisherOrganizationId = 42)
    {
    }

    public function isVerifiedActiveSlot(int $siteId, int $slotId): bool
    {
        foreach ($this->verifiedSlots as [$verifiedSiteId, $verifiedSlotId]) {
            if ($verifiedSiteId === $siteId && $verifiedSlotId === $slotId) {
                return true;
            }
        }

        return false;
    }

    public function publisherOrganizationIdForSlot(int $siteId, int $slotId): ?int
    {
        return $this->isVerifiedActiveSlot($siteId, $slotId) ? $this->publisherOrganizationId : null;
    }
}
