<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

final readonly class OrganizationMembership
{
    /**
     * @param list<string> $roleSlugs
     * @param list<string> $permissions
     */
    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $status,
        public array $roleSlugs,
        public array $permissions,
    ) {
    }
}
