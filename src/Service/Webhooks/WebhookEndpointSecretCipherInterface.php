<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

interface WebhookEndpointSecretCipherInterface
{
    public function generateSigningSecret(): string;

    public function generateSecret(): string;

    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;

    public function preview(string $plaintext): string;
}
