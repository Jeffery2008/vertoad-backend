<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

final readonly class TenantAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?OrganizationMembership $membership = null,
    ) {
    }
}
