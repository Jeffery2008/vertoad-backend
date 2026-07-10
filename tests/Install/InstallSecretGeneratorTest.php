<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\Install\InstallSecretGenerator;
use VertoAD\Service\OAuthClientSecretHasher;

final class InstallSecretGeneratorTest extends TestCase
{
    public function testGeneratesIndependentStrongSecretsAndValidRsaKeyPair(): void
    {
        $generator = new InstallSecretGenerator(2048);
        $secrets = $generator->generate();

        self::assertSame(32, strlen($secrets['installation_id']));
        self::assertInstanceOf(Key::class, Key::loadFromAsciiSafeString($secrets['app_key']));
        self::assertGreaterThanOrEqual(43, strlen($secrets['oauth_encryption_key']));
        self::assertStringStartsWith('voc_', $secrets['oauth_client_id']);
        self::assertStringStartsWith('vocs_', $secrets['oauth_client_secret']);
        self::assertStringStartsWith('vcron_', $secrets['cron_api_token']);
        self::assertStringStartsWith('vwhsec_', $secrets['webhook_signing_secret']);
        self::assertCount(count($secrets), array_unique($secrets));
        self::assertTrue((new OAuthClientSecretHasher())->verify(
            $secrets['oauth_client_secret'],
            (new OAuthClientSecretHasher())->hash($secrets['oauth_client_secret']),
        ));

        $keyPair = $generator->generateOAuthKeyPair();
        self::assertNotFalse(openssl_pkey_get_private($keyPair['private_key']));
        self::assertNotFalse(openssl_pkey_get_public($keyPair['public_key']));
    }

    public function testRejectsInvalidExplicitOpenSslConfiguration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate the OAuth RSA key pair.');

        (new InstallSecretGenerator(2048, __DIR__ . '/missing-openssl.cnf'))->generateOAuthKeyPair();
    }
}
