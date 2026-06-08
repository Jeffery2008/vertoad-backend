<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Auth\AuthenticatedUser;

interface FirstPartySessionRepositoryInterface
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser;

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool;
}
