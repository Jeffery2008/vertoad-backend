<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;
    use PHPUnit\Framework\TestCase;
    use VertoAD\Install\InstallFilesystem;
    use VertoAD\Install\InstallSecretGenerator;
    use VertoAD\Install\InstallState;

    final class InstallNativeFailureTest extends TestCase
    {
        /** @var list<string> */
        private array $temporaryDirectories = [];

        protected function tearDown(): void
        {
            $this->resetHooks();
            foreach (array_reverse($this->temporaryDirectories) as $path) {
                $this->removeDirectory($path);
            }
        }

        public function testReportsProcessLockAndEnvironmentReadFailures(): void
        {
            $root = $this->temporaryDirectory();
            mkdir($root . '/storage');
            $filesystem = new InstallFilesystem($root, openFile: static fn (): false => false);

            try {
                $filesystem->synchronized(static fn (): null => null);
                self::fail('Expected process lock open failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unable to open the installer process lock.', $exception->getMessage());
            }

            $this->resetHooks();
            $environment = $root . '/.env';
            file_put_contents($environment, "APP_ENV=local\n");

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to read the existing environment configuration.');
            (new InstallFilesystem($root, readFile: static fn (): false => false))->writeEnvironment(['APP_ENV' => 'production']);
        }

        public function testReportsSnapshotReadAndTemporaryFileOpenFailures(): void
        {
            $root = $this->temporaryDirectory();
            $environment = $root . '/.env';
            file_put_contents($environment, "APP_ENV=local\n");
            $filesystem = new InstallFilesystem($root, readFile: static fn (): false => false);

            try {
                $filesystem->commitInstallation([], ['private_key' => 'private', 'public_key' => 'public'], [], static fn (): null => null);
                self::fail('Expected snapshot read failure.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Unable to snapshot installer file', $exception->getMessage());
            }

            $this->resetHooks();
            $filesystem = new InstallFilesystem($root, openFile: static fn (): false => false);
            try {
                $filesystem->writeEnvironment(['APP_ENV' => 'production']);
                self::fail('Expected temporary file open failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unable to create a temporary installer file.', $exception->getMessage());
            }
        }

        public function testReportsWriteAndRollbackUnlinkFailures(): void
        {
            $root = $this->temporaryDirectory();
            $filesystem = new InstallFilesystem($root, writeFile: static fn (): false => false);

            try {
                $filesystem->writeEnvironment(['APP_ENV' => 'production']);
                self::fail('Expected installer file write failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unable to write an installer file.', $exception->getMessage());
            }

            $this->resetHooks();
            $lock = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock';
            $filesystem = new InstallFilesystem(
                $root,
                removeFile: static fn (string $path): bool => $path === $lock ? false : \unlink($path),
            );
            try {
                $filesystem->commitInstallation(
                    ['APP_ENV' => 'production'],
                    ['private_key' => 'private', 'public_key' => 'public'],
                    ['state' => 'installed'],
                    static fn (): never => throw new \RuntimeException('commit failed'),
                );
                self::fail('Expected filesystem rollback failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Installation failed and the previous filesystem state could not be fully restored.', $exception->getMessage());
            }
        }

        public function testPermanentLockFailsClosedWhenProcessLockCannotBeOpened(): void
        {
            $root = $this->temporaryDirectory();
            mkdir($root . '/storage');
            file_put_contents($root . '/storage/install.lock', '{}');
            $state = new InstallState($root);
            $processLock = $state->processLockPath();
            file_put_contents($processLock, '');
            $GLOBALS['vertoad_install_fopen_failure'] = static fn (string $path): bool => $path === $processLock;

            self::assertTrue($state->isInstalled());
        }

        public function testReportsOauthExportAndPublicKeyDetailFailures(): void
        {
            $GLOBALS['vertoad_install_openssl_export_failure'] = true;
            try {
                (new InstallSecretGenerator(2048))->generateOAuthKeyPair();
                self::fail('Expected OAuth private key export failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unable to export the OAuth private key.', $exception->getMessage());
            }

            $this->resetHooks();
            $GLOBALS['vertoad_install_openssl_details_failure'] = true;
            try {
                (new InstallSecretGenerator(2048))->generateOAuthKeyPair();
                self::fail('Expected OAuth public key export failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Unable to export the OAuth public key.', $exception->getMessage());
            }
        }

        public function testOauthGenerationWorksWithoutAnOpenSslConfigurationFile(): void
        {
            $GLOBALS['vertoad_install_is_file_failure'] = static fn (string $path): bool => str_ends_with(strtolower(str_replace('\\', '/', $path)), '/openssl.cnf');
            $GLOBALS['vertoad_install_openssl_supply_config'] = true;

            $pair = (new InstallSecretGenerator(2048))->generateOAuthKeyPair();

            self::assertNotFalse(openssl_pkey_get_private($pair['private_key']));
            self::assertNotFalse(openssl_pkey_get_public($pair['public_key']));
        }

        private function resetHooks(): void
        {
            foreach ([
                'vertoad_install_fopen_failure',
                'vertoad_install_is_file_failure',
                'vertoad_install_openssl_export_failure',
                'vertoad_install_openssl_details_failure',
                'vertoad_install_openssl_supply_config',
            ] as $name) {
                unset($GLOBALS[$name]);
            }
        }

        private function temporaryDirectory(): string
        {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-native-' . bin2hex(random_bytes(6));
            mkdir($path, 0700, true);
            $this->temporaryDirectories[] = $path;

            return $path;
        }

        private function removeDirectory(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }
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
