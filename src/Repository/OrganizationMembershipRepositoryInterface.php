<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Auth\OrganizationMembership;

interface OrganizationMembershipRepositoryInterface
{
    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership;
}
