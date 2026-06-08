<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;

final readonly class OAuthClientSecretHasher
{
    /**
     * @param callable|null $secretFactory
     */
    public function __construct(private mixed $secretFactory = null)
    {
    }

    public function generateSecret(): string
    {
        if (is_callable($this->secretFactory)) {
            return (string) ($this->secretFactory)();
        }

        return 'vocs_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $secret): string
    {
        if (trim($secret) === '') {
            throw new InvalidArgumentException('OAuth client secret must not be blank.');
        }

        return password_hash($secret, PASSWORD_DEFAULT);
    }

    public function verify(string $secret, string $hash): bool
    {
        return password_verify($secret, $hash);
    }
}
