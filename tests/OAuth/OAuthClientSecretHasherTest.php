<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\OAuthClientSecretHasher;

final class OAuthClientSecretHasherTest extends TestCase
{
    public function testGenerateSecretIsHighEntropyAndHashVerifiesWithoutExposingPlaintext(): void
    {
        $hasher = new OAuthClientSecretHasher(secretFactory: static fn (): string => 'oauth-secret-plain');

        $secret = $hasher->generateSecret();
        $hash = $hasher->hash($secret);

        self::assertSame('oauth-secret-plain', $secret);
        self::assertNotSame($secret, $hash);
        self::assertTrue($hasher->verify('oauth-secret-plain', $hash));
        self::assertFalse($hasher->verify('wrong-secret', $hash));
    }

    public function testHashRejectsBlankSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth client secret must not be blank.');

        (new OAuthClientSecretHasher())->hash('   ');
    }

    public function testDefaultSecretGenerationUsesVocsPrefix(): void
    {
        $secret = (new OAuthClientSecretHasher())->generateSecret();

        self::assertStringStartsWith('vocs_', $secret);
        self::assertGreaterThan(40, strlen($secret));
    }
}
