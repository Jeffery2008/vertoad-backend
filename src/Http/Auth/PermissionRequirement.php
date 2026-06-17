<?php

declare(strict_types=1);

namespace VertoAD\Http\Auth;

final readonly class PermissionRequirement
{
    public function __construct(
        public string $permission,
        public string $organizationIdAttribute = 'organization_id',
        public bool $platform = false,
        public bool $resolveOrganizationFromRequest = true,
    ) {
    }

    public static function forOrganization(string $permission, string $organizationIdAttribute = 'organization_id'): self
    {
        return new self($permission, $organizationIdAttribute);
    }

    public static function forAuthenticatedOrganization(string $permission): self
    {
        return new self($permission, resolveOrganizationFromRequest: false);
    }

    public static function forPlatform(string $permission): self
    {
        return new self($permission, platform: true);
    }
}
