<?php

declare(strict_types=1);

namespace VertoAD\Service;

use RuntimeException;

final class PlaceholderRechargeKeyPlaintextCipher implements RechargeKeyPlaintextCipherInterface
{
    private const PREFIX = 'placeholder:v1:';

    public function encrypt(string $plaintext): string
    {
        return self::PREFIX . base64_encode($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new RuntimeException('Recharge key ciphertext format is not supported.');
        }

        $decoded = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        if ($decoded === false) {
            throw new RuntimeException('Recharge key ciphertext is invalid.');
        }

        return $decoded;
    }
}
