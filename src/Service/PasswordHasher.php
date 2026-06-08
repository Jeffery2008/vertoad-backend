<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException('Password must not be blank.');
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
