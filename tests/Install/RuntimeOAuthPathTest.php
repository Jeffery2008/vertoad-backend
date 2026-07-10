<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;

final class RuntimeOAuthPathTest extends TestCase
{
    private string|false $previousPrivatePath;
    private string|false $previousPublicPath;

    protected function setUp(): void
    {
        $this->previousPrivatePath = getenv('OAUTH_PRIVATE_KEY_PATH');
        $this->previousPublicPath = getenv('OAUTH_PUBLIC_KEY_PATH');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('OAUTH_PRIVATE_KEY_PATH', $this->previousPrivatePath);
        $this->restoreEnvironment('OAUTH_PUBLIC_KEY_PATH', $this->previousPublicPath);
    }

    public function testRuntimeResolvesRelativeOauthPathsFromProjectRootAndPreservesAbsolutePaths(): void
    {
        $root = dirname(__DIR__, 2);
        putenv('OAUTH_PRIVATE_KEY_PATH=storage/oauth/custom-private.key');
        putenv('OAUTH_PUBLIC_KEY_PATH=storage/oauth/custom-public.key');

        $relative = require $root . '/config/settings.php';
        self::assertSame(
            $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'custom-private.key',
            $relative['oauth']['private_key_path'],
        );
        self::assertSame(
            $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'custom-public.key',
            $relative['oauth']['public_key_path'],
        );

        $absolutePrivate = $root . DIRECTORY_SEPARATOR . 'absolute-private.key';
        $absolutePublic = $root . DIRECTORY_SEPARATOR . 'absolute-public.key';
        putenv('OAUTH_PRIVATE_KEY_PATH=' . $absolutePrivate);
        putenv('OAUTH_PUBLIC_KEY_PATH=' . $absolutePublic);
        $absolute = require $root . '/config/settings.php';

        self::assertSame($absolutePrivate, $absolute['oauth']['private_key_path']);
        self::assertSame($absolutePublic, $absolute['oauth']['public_key_path']);

        putenv('OAUTH_PRIVATE_KEY_PATH');
        putenv('OAUTH_PUBLIC_KEY_PATH');
        $defaults = require $root . '/config/settings.php';
        self::assertSame($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'private.key', $defaults['oauth']['private_key_path']);
        self::assertSame($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'public.key', $defaults['oauth']['public_key_path']);
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}
