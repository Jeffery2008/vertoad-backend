<?php

declare(strict_types=1);

namespace VertoAD\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use VertoAD\Bootstrap\EnvironmentLoader;

final class EnvironmentLoaderTest extends TestCase
{
    private string|false $previousEnvironmentFile;
    private string|false $previousAppEnvironment;
    /** @var array<string, string|false> */
    private array $previousValues = [];
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $this->previousEnvironmentFile = getenv(EnvironmentLoader::ENV_FILE_VARIABLE);
        $this->previousAppEnvironment = getenv('APP_ENV');
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE);
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        $this->restore(EnvironmentLoader::ENV_FILE_VARIABLE, $this->previousEnvironmentFile);
        $this->restore('APP_ENV', $this->previousAppEnvironment);
        foreach ($this->previousValues as $key => $value) {
            $this->restore($key, $value);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testReturnsDefaultTargetWhenLocalEnvironmentFileDoesNotExist(): void
    {
        $root = $this->temporaryDirectory();

        self::assertSame($root . DIRECTORY_SEPARATOR . '.env', EnvironmentLoader::load($root . DIRECTORY_SEPARATOR));
    }

    public function testLoadsDefaultProjectEnvironmentFile(): void
    {
        $root = $this->temporaryDirectory();
        $key = 'VERTOAD_ENV_LOADER_DEFAULT_TEST';
        $this->remember($key);
        file_put_contents($root . '/.env', $key . "=loaded-default\n");

        self::assertSame(realpath($root . '/.env'), EnvironmentLoader::load($root));
        self::assertSame('loaded-default', getenv($key));
    }

    public function testExplicitAbsoluteEnvironmentFileTakesPrecedence(): void
    {
        $root = $this->temporaryDirectory();
        $external = $this->temporaryDirectory() . DIRECTORY_SEPARATOR . 'staging.env';
        $key = 'VERTOAD_ENV_LOADER_EXTERNAL_TEST';
        $this->remember($key);
        file_put_contents($root . '/.env', $key . "=project\n");
        file_put_contents($external, $key . "=external\n");
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=' . $external);

        self::assertSame(realpath($external), EnvironmentLoader::load($root));
        self::assertSame('external', getenv($key));
    }

    public function testHostedEnvironmentRequiresExplicitExternalEnvironmentFile(): void
    {
        foreach (['staging', 'prod', 'production'] as $environment) {
            $root = $this->temporaryDirectory();
            putenv('APP_ENV=' . $environment);
            try {
                EnvironmentLoader::load($root);
                self::fail('Expected hosted environment without an external env file to fail.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'VERTOAD_ENV_FILE must point to an external secret file in hosted environments.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testHostedEnvironmentLoadedFromProjectFileFailsClosed(): void
    {
        $root = $this->temporaryDirectory();
        putenv('APP_ENV=staging');
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env', "APP_ENV=staging\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'VERTOAD_ENV_FILE must point to an external secret file in hosted environments.',
        );
        EnvironmentLoader::load($root);
    }

    public function testRejectsRelativeAndMissingConfiguredPaths(): void
    {
        $root = $this->temporaryDirectory();
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=relative.env');

        try {
            EnvironmentLoader::load($root);
            self::fail('Expected a relative environment path to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('VERTOAD_ENV_FILE must contain an absolute path.', $exception->getMessage());
        }

        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=0');
        try {
            EnvironmentLoader::load($root);
            self::fail('Expected a false-like relative environment path to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('VERTOAD_ENV_FILE must contain an absolute path.', $exception->getMessage());
        }

        $missing = $root . DIRECTORY_SEPARATOR . 'missing.env';
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=' . $missing);
        try {
            EnvironmentLoader::load($root);
            self::fail('Expected a missing environment file to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The configured VertoAD environment file does not exist.', $exception->getMessage());
        }
    }

    public function testRejectsConfiguredEnvironmentFileInsideApplicationRoot(): void
    {
        $root = $this->temporaryDirectory();
        $environmentPath = $root . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'staging.env';
        mkdir(dirname($environmentPath), 0700, true);
        file_put_contents($environmentPath, "APP_ENV=staging\n");
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=' . $environmentPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VERTOAD_ENV_FILE must point outside the Git checkout.');
        EnvironmentLoader::load($root);
    }

    public function testRejectsConfiguredEnvironmentFileInsideParentCheckout(): void
    {
        $checkout = $this->temporaryDirectory();
        mkdir($checkout . DIRECTORY_SEPARATOR . '.git');
        $root = $checkout . DIRECTORY_SEPARATOR . 'backend';
        mkdir($root);
        file_put_contents($root . DIRECTORY_SEPARATOR . '.git', 'gitdir: ../.git/modules/backend');
        $environmentPath = $checkout . DIRECTORY_SEPARATOR . 'staging.env';
        file_put_contents($environmentPath, "APP_ENV=staging\n");
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=' . $environmentPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VERTOAD_ENV_FILE must point outside the Git checkout.');
        EnvironmentLoader::load($root);
    }

    public function testAbsolutePathRecognitionSupportsUnixWindowsAndUncPaths(): void
    {
        $method = new \ReflectionMethod(EnvironmentLoader::class, 'isAbsolutePath');

        self::assertSame(DIRECTORY_SEPARATOR !== '\\', $method->invoke(null, '/var/lib/vertoad/app.env'));
        self::assertSame(DIRECTORY_SEPARATOR === '\\', $method->invoke(null, 'C:\\vertoad\\env\\prod.env'));
        self::assertSame(DIRECTORY_SEPARATOR === '\\', $method->invoke(null, 'C:/vertoad/env/prod.env'));
        self::assertSame(DIRECTORY_SEPARATOR === '\\', $method->invoke(null, '\\\\server\\share\\prod.env'));
        self::assertSame(DIRECTORY_SEPARATOR === '\\', $method->invoke(null, '//server/share/prod.env'));
        self::assertFalse($method->invoke(null, 'env/prod.env'));
        self::assertFalse($method->invoke(null, 'C:env\\prod.env'));
        self::assertFalse($method->invoke(null, '\\env\\prod.env'));
        self::assertFalse($method->invoke(null, '\\\\server'));
    }

    private function remember(string $key): void
    {
        $this->previousValues[$key] = getenv($key);
        putenv($key);
    }

    private function restore(string $key, string|false $value): void
    {
        $value === false ? putenv($key) : putenv($key . '=' . $value);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-env-loader-' . bin2hex(random_bytes(6));
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
