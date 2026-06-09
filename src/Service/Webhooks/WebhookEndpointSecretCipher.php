<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;
use Defuse\Crypto\Key;
use RuntimeException;

final class WebhookEndpointSecretCipher implements WebhookEndpointSecretCipherInterface
{
    private const PREFIX = 'defuse:webhook:v1:';

    private Key $key;

    /** @var null|callable():string */
    private $secretGenerator;

    /**
     * @param null|callable():string $secretGenerator
     */
    public function __construct(string $appKey, ?callable $secretGenerator = null)
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            throw new RuntimeException('Webhook endpoint signing secret encryption key is required.');
        }

        try {
            $this->key = Key::loadFromAsciiSafeString($appKey);
        } catch (CryptoException) {
            throw new RuntimeException('Webhook endpoint signing secret encryption key is invalid.');
        }

        $this->secretGenerator = $secretGenerator;
    }

    public function generateSigningSecret(): string
    {
        if ($this->secretGenerator !== null) {
            return (string) ($this->secretGenerator)();
        }

        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function generateSecret(): string
    {
        return $this->generateSigningSecret();
    }

    public function encrypt(string $plaintext): string
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            throw new RuntimeException('Webhook endpoint signing secret plaintext is required.');
        }

        return self::PREFIX . Crypto::encrypt($plaintext, $this->key);
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new RuntimeException('Webhook endpoint signing secret ciphertext format is not supported.');
        }

        try {
            return Crypto::decrypt(substr($ciphertext, strlen(self::PREFIX)), $this->key);
        } catch (CryptoException) {
            throw new RuntimeException('Webhook endpoint signing secret ciphertext is invalid.');
        }
    }

    public function preview(string $plaintext): string
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            throw new RuntimeException('Webhook endpoint signing secret plaintext is required.');
        }

        $suffix = substr($plaintext, -6);
        return 'whsec_...' . $suffix;
    }
}
