<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;

final readonly class OAuthScopeCatalog
{
    /** @var list<string> */
    private const array OPEN_API_SCOPES = [
        'attribution.conversion.write.own',
        'campaign.read.own',
        'campaign.write.own',
        'creative.design.read.own',
        'creative.design.write.own',
        'creative.read.own',
        'creative.template.read.own',
        'creative.template.write.own',
        'creative.write.own',
        'report.export.own',
        'report.read.own',
    ];

    public function __construct(
        private OrganizationMembershipRepositoryInterface $memberships,
        private PermissionMatcher $permissions,
    ) {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::OPEN_API_SCOPES;
    }

    public static function isGrantable(string $scope): bool
    {
        return in_array($scope, self::OPEN_API_SCOPES, true);
    }

    /** @param list<string> $requestedScopes */
    public function assertCreatorMayGrant(AuthenticatedUser $creator, int $organizationId, array $requestedScopes): void
    {
        $membership = $this->memberships->findActiveMembership($creator->id, $organizationId);
        if ($membership === null) {
            throw new InvalidArgumentException('OAuth client creator must be an active member of the target organization.');
        }

        foreach ($requestedScopes as $scope) {
            if (!self::isGrantable($scope)) {
                throw new InvalidArgumentException(sprintf('OAuth scope is not available through the advertiser Open API: %s.', $scope));
            }

            if (!$this->permissions->allows($membership->permissions, $scope)) {
                throw new InvalidArgumentException(sprintf("OAuth scope exceeds the creator's permissions in this organization: %s.", $scope));
            }
        }
    }
}
