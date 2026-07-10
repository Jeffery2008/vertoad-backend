<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Publisher\AdSlot;

interface AdSlotRepositoryInterface
{
    public function store(AdSlot $slot): AdSlot;

    /**
     * @return list<AdSlot>
     */
    public function listForSite(int $siteId): array;

    public function findForSiteInOrganization(int $siteId, int $slotId, int $organizationId): ?AdSlot;
}
