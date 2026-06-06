<?php

declare(strict_types=1);

namespace VertoAD\Service;

use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\TenantAccessDecision;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;

final class TenantAccessService
{
    public function __construct(
        private readonly OrganizationMembershipRepositoryInterface $memberships,
        private readonly PermissionMatcher $permissions,
    ) {
    }

    public function decide(AuthenticatedUser $user, int $organizationId, string $requiredPermission): TenantAccessDecision
    {
        if ($user->isSuperAdmin) {
            return new TenantAccessDecision(true, 'super_admin');
        }

        $membership = $this->memberships->findActiveMembership($user->id, $organizationId);
        if ($membership === null) {
            return new TenantAccessDecision(false, 'membership_required');
        }

        if (!$this->permissions->allows($membership->permissions, $requiredPermission)) {
            return new TenantAccessDecision(false, 'permission_required', $membership);
        }

        return new TenantAccessDecision(true, 'allowed', $membership);
    }
}
