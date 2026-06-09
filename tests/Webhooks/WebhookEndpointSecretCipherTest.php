<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;

final class WebhookEndpointSecretCipherTest extends TestCase
{
    public function testGeneratesEncryptsDecryptsAndPreviewsEndpointSigningSecrets(): void
    {
        $cipher = new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString());

        $secret = $cipher->generateSigningSecret();
        $aliasSecret = $cipher->generateSecret();
        $ciphertext = $cipher->encrypt('  whsec_endpoint_secret  ');

        self::assertStringStartsWith('whsec_', $secret);
        self::assertStringStartsWith('whsec_', $aliasSecret);
        self::assertNotSame($secret, $aliasSecret);
        self::assertStringStartsWith('defuse:webhook:v1:', $ciphertext);
        self::assertStringNotContainsString('whsec_endpoint_secret', $ciphertext);
        self::assertSame('whsec_endpoint_secret', $cipher->decrypt($ciphertext));
        self::assertSame('whsec_...secret', $cipher->preview('whsec_endpoint_secret'));
    }

    public function testRejectsMissingAndInvalidEncryptionKeys(): void
    {
        foreach ([
            ['', 'Webhook endpoint signing secret encryption key is required.'],
            ['base64:replace-with-strong-random-key', 'Webhook endpoint signing secret encryption key is invalid.'],
        ] as [$appKey, $message]) {
            try {
                new WebhookEndpointSecretCipher($appKey);
                self::fail('Expected invalid webhook cipher key to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testRejectsInvalidPlaintextAndCiphertext(): void
    {
        $cipher = new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString());

        foreach ([
            static fn (): string => $cipher->encrypt('   '),
            static fn (): string => $cipher->preview(''),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected blank webhook signing secret plaintext to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('Webhook endpoint signing secret plaintext is required.', $exception->getMessage());
            }
        }

        foreach ([
            ['encrypted:whsec_endpoint_secret', 'Webhook endpoint signing secret ciphertext format is not supported.'],
            ['defuse:webhook:v1:not valid encrypted payload', 'Webhook endpoint signing secret ciphertext is invalid.'],
        ] as [$ciphertext, $message]) {
            try {
                $cipher->decrypt($ciphertext);
                self::fail('Expected invalid webhook signing secret ciphertext to be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
