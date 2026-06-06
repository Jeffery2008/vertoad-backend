<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $email,
        public bool $isSuperAdmin,
    ) {
    }
}
