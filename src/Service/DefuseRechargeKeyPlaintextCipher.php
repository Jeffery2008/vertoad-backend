<?php

declare(strict_types=1);

namespace VertoAD\Service;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;
use Defuse\Crypto\Key;
use RuntimeException;

final class DefuseRechargeKeyPlaintextCipher implements RechargeKeyPlaintextCipherInterface
{
    private const PREFIX = 'defuse:v1:';

    private Key $key;

    public function __construct(string $appKey)
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            throw new RuntimeException('Recharge key encryption key is required.');
        }

        try {
            $this->key = Key::loadFromAsciiSafeString($appKey);
        } catch (CryptoException) {
            throw new RuntimeException('Recharge key encryption key is invalid.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        return self::PREFIX . Crypto::encrypt($plaintext, $this->key);
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new RuntimeException('Recharge key ciphertext format is not supported.');
        }

        try {
            return Crypto::decrypt(substr($ciphertext, strlen(self::PREFIX)), $this->key);
        } catch (CryptoException) {
            throw new RuntimeException('Recharge key ciphertext is invalid.');
        }
    }
}
