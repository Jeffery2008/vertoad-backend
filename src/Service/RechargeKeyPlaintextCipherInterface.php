<?php

declare(strict_types=1);

namespace VertoAD\Service;

interface RechargeKeyPlaintextCipherInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
