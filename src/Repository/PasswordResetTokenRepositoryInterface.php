<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Auth\PasswordResetToken;

interface PasswordResetTokenRepositoryInterface
{
    public function store(PasswordResetToken $token): PasswordResetToken;

    public function consumeUsableToken(string $tokenHash, DateTimeImmutable $usedAt): ?PasswordResetToken;
}
