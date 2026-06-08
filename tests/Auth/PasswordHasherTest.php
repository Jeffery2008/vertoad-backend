<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\PasswordHasher;

final class PasswordHasherTest extends TestCase
{
    public function testHashDoesNotExposePlaintextAndVerifiesPassword(): void
    {
        $hasher = new PasswordHasher();

        $hash = $hasher->hash('correct horse battery staple');

        self::assertNotSame('correct horse battery staple', $hash);
        self::assertSame(PASSWORD_DEFAULT, password_get_info($hash)['algo']);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testHashRejectsBlankPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must not be blank.');

        (new PasswordHasher())->hash('   ');
    }
}
