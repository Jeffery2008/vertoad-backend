<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;
use VertoAD\Install\InstallFilesystem;
use VertoAD\Install\InstallHttpException;

final class InstallFilesystemTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
    }

    public function testWritesMergedEnvironmentAtomicallyAndPreservesUnknownValues(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env.example', <<<'ENV'
# keep this comment
APP_ENV=local
CUSTOM_VALUE="preserve-me"
APP_ENV=duplicate
ENV);
        $filesystem = new InstallFilesystem($root);
        $filesystem->writeEnvironment([
            'APP_ENV' => 'production',
            'APP_INSTALLED' => true,
            'DB_PORT' => 3306,
            'RATIO' => 0.5,
            'DB_PASSWORD' => 'p$a\\ss"word',
            'EMPTY_VALUE' => null,
        ]);

        $contents = (string) file_get_contents($root . DIRECTORY_SEPARATOR . '.env');
        self::assertStringContainsString('# keep this comment', $contents);
        self::assertStringContainsString('CUSTOM_VALUE="preserve-me"', $contents);
        self::assertSame(1, substr_count($contents, 'APP_ENV='));
        self::assertStringContainsString('APP_ENV="production"', $contents);
        self::assertStringContainsString('APP_INSTALLED=true', $contents);
        self::assertStringContainsString('DB_PORT=3306', $contents);
        self::assertStringContainsString('RATIO=0.5', $contents);
        self::assertStringContainsString('DB_PASSWORD="p\\$a\\\\ss\\"word"', $contents);
        self::assertStringContainsString('EMPTY_VALUE=', $contents);
        self::assertStringEndsWith(PHP_EOL, $contents);

        $filesystem->writeEnvironment(['APP_INSTALLED' => false]);
        $updated = (string) file_get_contents($root . DIRECTORY_SEPARATOR . '.env');
        self::assertStringContainsString('APP_INSTALLED=false', $updated);
        self::assertStringContainsString('CUSTOM_VALUE="preserve-me"', $updated);
    }

    public function testWritesAndRestoresAnExternalEnvironmentTarget(): void
    {
        $root = $this->temporaryDirectory();
        $secretDirectory = $this->temporaryDirectory();
        $environmentPath = $secretDirectory . DIRECTORY_SEPARATOR . 'staging.env';
        file_put_contents(
            $environmentPath,
            "INSTALL_TOKEN=keep-me\nOAUTH_PRIVATE_KEY_PASSPHRASE=legacy-passphrase\nCUSTOM=external\n",
        );
        $filesystem = new InstallFilesystem($root, environmentPath: $environmentPath);

        $filesystem->writeEnvironment(['APP_INSTALLED' => true]);
        self::assertStringContainsString('APP_INSTALLED=true', (string) file_get_contents($environmentPath));
        self::assertFileDoesNotExist($root . DIRECTORY_SEPARATOR . '.env');

        try {
            $filesystem->commitInstallation(
                ['APP_INSTALLED' => true, 'INSTALL_TOKEN' => ''],
                ['private_key' => 'PRIVATE', 'public_key' => 'PUBLIC'],
                ['state' => 'installed'],
                static fn (): never => throw new \RuntimeException('database commit failed'),
            );
            self::fail('Expected database commit failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database commit failed', $exception->getMessage());
        }

        $restored = (string) file_get_contents($environmentPath);
        self::assertStringContainsString('INSTALL_TOKEN=keep-me', $restored);
        self::assertStringContainsString('OAUTH_PRIVATE_KEY_PASSPHRASE=legacy-passphrase', $restored);
        self::assertStringContainsString('CUSTOM=external', $restored);
        self::assertStringContainsString('APP_INSTALLED=true', $restored);
        self::assertFileDoesNotExist($root . '/storage/install.lock');
    }

    public function testSuccessfulCommitClearsTokenOnlyInExternalEnvironmentTarget(): void
    {
        $root = $this->temporaryDirectory();
        $secretDirectory = $this->temporaryDirectory();
        $environmentPath = $secretDirectory . DIRECTORY_SEPARATOR . 'staging.env';
        file_put_contents(
            $environmentPath,
            "INSTALL_TOKEN=installer-secret\nexport oauth_private_key_passphrase=legacy-passphrase\nCUSTOM=external\n",
        );
        $databaseCommitted = false;

        (new InstallFilesystem($root, environmentPath: $environmentPath))->commitInstallation(
            [
                'APP_INSTALLED' => true,
                'INSTALL_TOKEN' => '',
                'OAUTH_PRIVATE_KEY_PASSPHRASE' => 'new-payload-passphrase',
            ],
            ['private_key' => 'PRIVATE', 'public_key' => 'PUBLIC'],
            ['state' => 'installed'],
            static function () use (&$databaseCommitted): void {
                $databaseCommitted = true;
            },
        );

        $environment = (string) file_get_contents($environmentPath);
        self::assertTrue($databaseCommitted);
        self::assertStringContainsString('INSTALL_TOKEN=""', $environment);
        self::assertSame(1, substr_count($environment, 'INSTALL_TOKEN='));
        self::assertStringNotContainsString('installer-secret', $environment);
        self::assertStringNotContainsString('OAUTH_PRIVATE_KEY_PASSPHRASE', strtoupper($environment));
        self::assertStringNotContainsString('legacy-passphrase', $environment);
        self::assertStringNotContainsString('new-payload-passphrase', $environment);
        self::assertStringContainsString('CUSTOM=external', $environment);
        self::assertFileDoesNotExist($root . DIRECTORY_SEPARATOR . '.env');
        self::assertFileExists($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock');
    }

    public function testEnvironmentMergeClearsExportedBomPrefixedCaseVariantsAndDuplicates(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents(
            $environmentPath,
            "\xEF\xBB\xBFexport install_token=first-secret\r\nINSTALL_TOKEN=second-secret\r\nCUSTOM=preserved\r\n",
        );

        (new InstallFilesystem($root))->writeEnvironment(['INSTALL_TOKEN' => '']);

        $environment = (string) file_get_contents($environmentPath);
        self::assertSame(1, substr_count($environment, 'INSTALL_TOKEN='));
        self::assertStringStartsWith('INSTALL_TOKEN=""' . PHP_EOL, $environment);
        self::assertStringNotContainsString('first-secret', $environment);
        self::assertStringNotContainsString('second-secret', $environment);
        self::assertStringNotContainsString("\xEF\xBB\xBF", $environment);
        self::assertStringContainsString('CUSTOM=preserved', $environment);
    }

    public function testCommitRejectsMissingOrRetainedInstallTokenBeforeWriting(): void
    {
        foreach ([[], ['INSTALL_TOKEN' => 'still-enabled'], ['INSTALL_TOKEN' => null], ['INSTALL_TOKEN' => false]] as $environment) {
            $root = $this->temporaryDirectory();
            $databaseCommitted = false;
            try {
                (new InstallFilesystem($root))->commitInstallation(
                    $environment,
                    ['private_key' => 'PRIVATE', 'public_key' => 'PUBLIC'],
                    ['state' => 'installed'],
                    static function () use (&$databaseCommitted): void {
                        $databaseCommitted = true;
                    },
                );
                self::fail('Expected an uncleared installation token to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('A completed installation must clear INSTALL_TOKEN.', $exception->getMessage());
            }

            self::assertFalse($databaseCommitted);
            self::assertFileDoesNotExist($root . DIRECTORY_SEPARATOR . '.env');
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'storage');
        }
    }

    public function testAtomicReplaceRetriesTransientWindowsStyleSharingFailure(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env', "APP_ENV=old\n");
        $replaceAttempts = 0;
        $delays = [];
        $filesystem = new InstallFilesystem(
            $root,
            replaceFile: static function (string $source, string $target) use (&$replaceAttempts): bool {
                $replaceAttempts++;
                return $replaceAttempts >= 3 && rename($source, $target);
            },
            delay: static function (int $microseconds) use (&$delays): void {
                $delays[] = $microseconds;
            },
        );

        $filesystem->writeEnvironment(['APP_ENV' => 'staging']);

        self::assertSame(3, $replaceAttempts);
        self::assertSame([50_000, 50_000], $delays);
        self::assertSame('APP_ENV="staging"' . PHP_EOL, file_get_contents($root . DIRECTORY_SEPARATOR . '.env'));
        self::assertSame([], $this->temporaryInstallerFiles($root));
    }

    public function testAtomicReplaceFailureKeepsOldFileAndRemovesTemporarySecret(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $replaceAttempts = 0;
        $delays = 0;
        $filesystem = new InstallFilesystem(
            $root,
            replaceFile: static function () use (&$replaceAttempts): bool {
                $replaceAttempts++;
                return false;
            },
            delay: static function () use (&$delays): void {
                $delays++;
            },
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected atomic replacement to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to atomically replace .env.', $exception->getMessage());
        }

        self::assertSame(5, $replaceAttempts);
        self::assertSame(4, $delays);
        self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
        self::assertSame([], $this->temporaryInstallerFiles($root));
    }

    public function testPermissionFailureKeepsOldFileAndRemovesTemporarySecret(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $replaceCalled = false;
        $filesystem = new InstallFilesystem(
            $root,
            changeMode: static fn (): false => false,
            replaceFile: static function () use (&$replaceCalled): bool {
                $replaceCalled = true;
                return true;
            },
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected permission hardening to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to secure temporary installer file permissions.', $exception->getMessage());
        }

        self::assertFalse($replaceCalled);
        self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
        self::assertSame([], $this->temporaryInstallerFiles($root));
    }

    public function testThrownPermissionFailureKeepsOldFileAndRemovesTemporarySecret(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $filesystem = new InstallFilesystem(
            $root,
            changeMode: static fn (): never => throw new \RuntimeException('permission callback failed'),
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected permission hardening to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('permission callback failed', $exception->getMessage());
        }

        self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
        self::assertSame([], $this->temporaryInstallerFiles($root));
    }

    public function testCleanupFailureIsReportedWithoutHidingAtomicReplaceFailure(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $filesystem = new InstallFilesystem(
            $root,
            removeFile: static fn (): false => false,
            replaceFile: static fn (): false => false,
            delay: static fn (): null => null,
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected temporary file cleanup to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installer file update failed and its temporary file could not be removed.', $exception->getMessage());
            self::assertSame('Unable to atomically replace .env.', $exception->getPrevious()?->getMessage());
        }

        self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
        self::assertCount(1, $this->temporaryInstallerFiles($root));
    }

    public function testThrownReplaceFailureKeepsOldFileAndRemovesTemporarySecret(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $filesystem = new InstallFilesystem(
            $root,
            replaceFile: static fn (): never => throw new \RuntimeException('replace callback failed'),
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected the replacement callback to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('replace callback failed', $exception->getMessage());
        }

        self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
        self::assertSame([], $this->temporaryInstallerFiles($root));
    }

    public function testThrownCleanupFailureIsWrappedWithOriginalWriteFailure(): void
    {
        $root = $this->temporaryDirectory();
        $filesystem = new InstallFilesystem(
            $root,
            writeFile: static fn (): never => throw new \RuntimeException('write callback failed'),
            removeFile: static fn (): never => throw new \RuntimeException('cleanup callback failed'),
        );

        try {
            $filesystem->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected temporary file cleanup to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Installer file update failed and its temporary file could not be removed.', $exception->getMessage());
            self::assertSame('write callback failed', $exception->getPrevious()?->getMessage());
        }

        self::assertCount(1, $this->temporaryInstallerFiles($root));
    }

    public function testWindowsReadOnlyTargetFailsClosed(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertNotSame('Windows', PHP_OS_FAMILY);
            return;
        }

        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        self::assertTrue(chmod($environmentPath, 0444));

        try {
            (new InstallFilesystem($root, delay: static fn (): null => null))
                ->writeEnvironment(['INSTALL_TOKEN' => 'new-secret']);
            self::fail('Expected a read-only Windows target to reject replacement.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to atomically replace .env.', $exception->getMessage());
            self::assertSame("INSTALL_TOKEN=old-secret\n", file_get_contents($environmentPath));
            self::assertSame([], $this->temporaryInstallerFiles($root));
        } finally {
            chmod($environmentPath, 0666);
        }
    }

    public function testWindowsAtomicReplacementRetainsInheritedDirectoryAcl(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertNotSame('Windows', PHP_OS_FAMILY);
            return;
        }

        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($environmentPath, "INSTALL_TOKEN=old-secret\n");
        $before = $this->windowsAclListing($environmentPath);

        (new InstallFilesystem($root))->writeEnvironment(['INSTALL_TOKEN' => '']);

        self::assertSame($before, $this->windowsAclListing($environmentPath));
        self::assertSame('INSTALL_TOKEN=""' . PHP_EOL, file_get_contents($environmentPath));
    }

    public function testWritesEnvironmentWithoutTemplateAndRejectsInvalidKeys(): void
    {
        $root = $this->temporaryDirectory();
        $filesystem = new InstallFilesystem($root);
        $filesystem->writeEnvironment(['APP_INSTALLED' => false]);
        self::assertSame('APP_INSTALLED=false' . PHP_EOL, file_get_contents($root . DIRECTORY_SEPARATOR . '.env'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment key is invalid');
        $filesystem->writeEnvironment(['lowercase' => 'no']);
    }

    public function testWritesOauthKeysAndPermanentLock(): void
    {
        $root = $this->temporaryDirectory();
        $filesystem = new InstallFilesystem($root);
        $paths = $filesystem->writeOAuthKeyPair([
            'private_key' => 'PRIVATE KEY',
            'public_key' => 'PUBLIC KEY',
        ]);

        self::assertSame('PRIVATE KEY', file_get_contents(str_replace('/', DIRECTORY_SEPARATOR, $paths['private_key_path'])));
        self::assertSame('PUBLIC KEY', file_get_contents(str_replace('/', DIRECTORY_SEPARATOR, $paths['public_key_path'])));
        self::assertSame([
            'private_key_path' => 'storage/oauth/private.key',
            'public_key_path' => 'storage/oauth/public.key',
        ], $filesystem->oauthEnvironmentKeyPaths());
        $filesystem->writePermanentLock(['installation_id' => 'abc', 'admin_user_id' => 1]);
        $lock = json_decode((string) file_get_contents($root . '/storage/install.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('abc', $lock['installation_id']);
        self::assertSame(1, $lock['admin_user_id']);

        $this->expectException(InstallHttpException::class);
        $this->expectExceptionMessage('already been installed');
        $filesystem->writePermanentLock(['installation_id' => 'different']);
    }

    public function testSynchronizedReturnsValueAndRejectsConcurrentInstaller(): void
    {
        $root = $this->temporaryDirectory();
        $filesystem = new InstallFilesystem($root);
        self::assertSame('result', $filesystem->synchronized(static fn (): string => 'result'));

        $handle = fopen($root . '/storage/.installing.lock', 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $filesystem->synchronized(static fn (): null => null);
            self::fail('Expected concurrent installation to be rejected.');
        } catch (InstallHttpException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('installation_in_progress', $exception->errorCode);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testSynchronizedReleasesLockWhenOperationThrows(): void
    {
        $root = $this->temporaryDirectory();
        $filesystem = new InstallFilesystem($root);
        try {
            $filesystem->synchronized(static fn (): never => throw new \RuntimeException('failed'));
            self::fail('Expected operation failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        self::assertSame('retry', $filesystem->synchronized(static fn (): string => 'retry'));
    }

    public function testCommitInstallationRestoresExistingFilesWhenDatabaseCommitFails(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . '/.env', "INSTALL_TOKEN=keep-me\nCUSTOM=original\n");
        $filesystem = new InstallFilesystem($root);
        $filesystem->writeOAuthKeyPair(['private_key' => 'OLD PRIVATE', 'public_key' => 'OLD PUBLIC']);

        try {
            $filesystem->commitInstallation(
                ['APP_INSTALLED' => true, 'INSTALL_TOKEN' => ''],
                ['private_key' => 'NEW PRIVATE', 'public_key' => 'NEW PUBLIC'],
                ['state' => 'installed'],
                static fn (): never => throw new \RuntimeException('database commit failed'),
            );
            self::fail('Expected database commit failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database commit failed', $exception->getMessage());
        }

        self::assertSame("INSTALL_TOKEN=keep-me\nCUSTOM=original\n", file_get_contents($root . '/.env'));
        self::assertSame('OLD PRIVATE', file_get_contents($root . '/storage/oauth/private.key'));
        self::assertSame('OLD PUBLIC', file_get_contents($root . '/storage/oauth/public.key'));
        self::assertFileDoesNotExist($root . '/storage/install.lock');
    }

    public function testCommitInstallationReportsFilesystemRollbackFailure(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents($root . '/.env', "CUSTOM=original\n");
        $filesystem = new InstallFilesystem($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous filesystem state could not be fully restored');
            $filesystem->commitInstallation(
                ['APP_INSTALLED' => true, 'INSTALL_TOKEN' => ''],
            ['private_key' => 'PRIVATE', 'public_key' => 'PUBLIC'],
            ['state' => 'installed'],
            static function () use ($root): never {
                unlink($root . '/.env');
                mkdir($root . '/.env');
                throw new \RuntimeException('database commit failed');
            },
        );
    }

    public function testCommitInstallationRejectsNonRegularTargetBeforeWriting(): void
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/.env');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Installer target is not a regular file');
        (new InstallFilesystem($root))->commitInstallation(
            ['APP_INSTALLED' => true, 'INSTALL_TOKEN' => ''],
            ['private_key' => 'PRIVATE', 'public_key' => 'PUBLIC'],
            ['state' => 'installed'],
            static fn (): null => null,
        );
    }

    public function testRejectsRootThatCannotContainStorageDirectory(): void
    {
        $rootFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-root-file-' . bin2hex(random_bytes(6));
        file_put_contents($rootFile, 'not a directory');
        $this->temporaryPaths[] = $rootFile;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to create installer directory');
        (new InstallFilesystem($rootFile))->synchronized(static fn (): null => null);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-fs-' . bin2hex(random_bytes(6));
        mkdir($path, 0700, true);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function temporaryInstallerFiles(string $directory): array
    {
        $files = [];
        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.tmp')) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function windowsAclListing(string $path): string
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'vertoad-acl-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'vertoad-acl-err-');
        if ($stdoutPath === false || $stderrPath === false) {
            throw new \RuntimeException('Unable to allocate Windows ACL assertion output files.');
        }

        $command = ['icacls.exe', $path];
        try {
            $pipes = [];
            $process = proc_open($command, [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ], $pipes, options: ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to start icacls for the Windows ACL assertion.');
            }

            $exitCode = proc_close($process);
            $stdout = file_get_contents($stdoutPath);
            $stderr = file_get_contents($stderrPath);
            if ($exitCode !== 0 || $stdout === false) {
                throw new \RuntimeException('Unable to read the Windows ACL: ' . trim((string) $stderr));
            }

            return trim($stdout);
        } finally {
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
