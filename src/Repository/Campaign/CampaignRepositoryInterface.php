<?php

declare(strict_types=1);

namespace VertoAD\Repository\Campaign;

use VertoAD\Domain\Campaign\Campaign;

interface CampaignRepositoryInterface
{
    /** @return list<Campaign> */
    public function listForOrganization(int $organizationId): array;

    public function find(int $organizationId, int $campaignId): ?Campaign;

    public function create(Campaign $campaign): Campaign;

    public function update(Campaign $campaign): Campaign;

    public function pauseIfActive(int $organizationId, int $campaignId, string $reason): bool;
}
