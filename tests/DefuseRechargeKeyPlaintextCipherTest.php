<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Defuse\Crypto\Key;
use VertoAD\Service\DefuseRechargeKeyPlaintextCipher;

final class DefuseRechargeKeyPlaintextCipherTest extends TestCase
{
    public function testEncryptAndDecryptRoundTripWithAuthenticatedAppKeyCiphertext(): void
    {
        $cipher = new DefuseRechargeKeyPlaintextCipher(Key::createNewRandomKey()->saveToAsciiSafeString());

        $firstCiphertext = $cipher->encrypt('rk_live_ABC123');
        $secondCiphertext = $cipher->encrypt('rk_live_ABC123');

        self::assertStringStartsWith('defuse:v1:', $firstCiphertext);
        self::assertStringNotContainsString('rk_live_ABC123', $firstCiphertext);
        self::assertNotSame($firstCiphertext, $secondCiphertext);
        self::assertSame('rk_live_ABC123', $cipher->decrypt($firstCiphertext));
        self::assertSame('rk_live_ABC123', $cipher->decrypt($secondCiphertext));
    }

    public function testDecryptRejectsUnsupportedCiphertextPrefix(): void
    {
        $cipher = new DefuseRechargeKeyPlaintextCipher(Key::createNewRandomKey()->saveToAsciiSafeString());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key ciphertext format is not supported.');

        $cipher->decrypt('encrypted:rk_live_ABC123');
    }

    public function testDecryptRejectsInvalidBase64Payload(): void
    {
        $cipher = new DefuseRechargeKeyPlaintextCipher(Key::createNewRandomKey()->saveToAsciiSafeString());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key ciphertext is invalid.');

        $cipher->decrypt('defuse:v1:not valid encrypted payload');
    }

    public function testConstructorRejectsMissingAppKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key encryption key is required.');

        new DefuseRechargeKeyPlaintextCipher('');
    }

    public function testConstructorRejectsInvalidAppKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recharge key encryption key is invalid.');

        new DefuseRechargeKeyPlaintextCipher('base64:replace-with-strong-random-key');
    }
}
