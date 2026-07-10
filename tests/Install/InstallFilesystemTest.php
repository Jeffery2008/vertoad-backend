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
            ['APP_INSTALLED' => true],
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
            ['APP_INSTALLED' => true],
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
