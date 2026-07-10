<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;
use VertoAD\Install\InstallState;

final class InstallStateTest extends TestCase
{
    private string|false $previousInstalled;
    private string|false $previousEnvironment;
    private string|false $previousTestBypass;

    protected function setUp(): void
    {
        $this->previousInstalled = getenv('APP_INSTALLED');
        $this->previousEnvironment = getenv('APP_ENV');
        $this->previousTestBypass = getenv('VERTOAD_INSTALL_TEST_BYPASS');
        putenv('APP_ENV=testing');
        putenv('APP_INSTALLED=false');
        putenv('VERTOAD_INSTALL_TEST_BYPASS=false');
    }

    protected function tearDown(): void
    {
        $this->previousInstalled === false
            ? putenv('APP_INSTALLED')
            : putenv('APP_INSTALLED=' . $this->previousInstalled);
        $this->previousEnvironment === false
            ? putenv('APP_ENV')
            : putenv('APP_ENV=' . $this->previousEnvironment);
        $this->previousTestBypass === false
            ? putenv('VERTOAD_INSTALL_TEST_BYPASS')
            : putenv('VERTOAD_INSTALL_TEST_BYPASS=' . $this->previousTestBypass);
    }

    public function testEnvironmentOrPermanentLockMarksApplicationInstalled(): void
    {
        $root = $this->temporaryDirectory();
        $state = new InstallState($root);

        self::assertFalse($state->isInstalled());
        self::assertFalse($state->hasPermanentLock());
        self::assertSame($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock', $state->lockPath());

        putenv('APP_INSTALLED=true');
        self::assertTrue($state->isInstalled());

        putenv('APP_ENV=production');
        self::assertFalse($state->isInstalled());

        putenv('VERTOAD_INSTALL_TEST_BYPASS=true');
        self::assertTrue($state->isInstalled());
        putenv('VERTOAD_INSTALL_TEST_BYPASS=false');

        putenv('APP_ENV=testing');
        putenv('APP_INSTALLED=false');
        mkdir($root . DIRECTORY_SEPARATOR . 'storage');
        file_put_contents($state->lockPath(), '{}');
        self::assertTrue($state->hasPermanentLock());
        self::assertTrue($state->isInstalled());

        $this->removeDirectory($root);
    }

    public function testPermanentLockDoesNotEnableApplicationUntilInstallerProcessLockIsReleased(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/storage');
        $state = new InstallState($root);
        file_put_contents($state->lockPath(), '{}');
        $processLock = fopen($state->processLockPath(), 'c+b');
        self::assertIsResource($processLock);
        self::assertTrue(flock($processLock, LOCK_EX | LOCK_NB));

        self::assertFalse($state->isInstalled());
        flock($processLock, LOCK_UN);
        fclose($processLock);
        self::assertTrue($state->isInstalled());

        $this->removeDirectory($root);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-state-' . bin2hex(random_bytes(6));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
