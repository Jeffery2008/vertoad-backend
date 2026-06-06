<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Auth\AuthenticatedUser;

interface UserIdentityRepositoryInterface
{
    public function findAuthenticatedUser(int $userId): ?AuthenticatedUser;
}
