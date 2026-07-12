<?php

declare(strict_types=1);

namespace VertoAD\Bootstrap;

use Dotenv\Dotenv;

final class EnvironmentLoader
{
    public const ENV_FILE_VARIABLE = 'VERTOAD_ENV_FILE';

    public static function load(string $rootPath): string
    {
        $configured = getenv(self::ENV_FILE_VARIABLE);
        $configuredPath = trim($configured === false ? '' : $configured);
        if ($configuredPath === '') {
            $defaultPath = rtrim($rootPath, "\\/") . DIRECTORY_SEPARATOR . '.env';
            if (!is_file($defaultPath)) {
                self::rejectHostedDefaultEnvironment();

                return $defaultPath;
            }

            $environmentPath = realpath($defaultPath) ?: $defaultPath;
        } else {
            if (!self::isAbsolutePath($configuredPath)) {
                throw new \RuntimeException(self::ENV_FILE_VARIABLE . ' must contain an absolute path.');
            }
            if (!is_file($configuredPath)) {
                throw new \RuntimeException('The configured VertoAD environment file does not exist.');
            }

            $environmentPath = realpath($configuredPath) ?: $configuredPath;
            if (self::isWithinRoot($environmentPath, self::checkoutRoot($rootPath))) {
                throw new \RuntimeException(self::ENV_FILE_VARIABLE . ' must point outside the Git checkout.');
            }
        }

        Dotenv::createUnsafeImmutable(dirname($environmentPath), basename($environmentPath))->load();
        if ($configuredPath === '') {
            self::rejectHostedDefaultEnvironment();
        }

        return $environmentPath;
    }

    private static function rejectHostedDefaultEnvironment(): void
    {
        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
        if (in_array($environment, ['staging', 'prod', 'production'], true)) {
            throw new \RuntimeException(
                self::ENV_FILE_VARIABLE . ' must point to an external secret file in hosted environments.',
            );
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        $unixAbsolute = str_starts_with($path, '/');
        $windowsAbsolute = preg_match('/^[a-zA-Z]:[\\\\\/]/D', $path) === 1
            || preg_match('~^(?:\\\\\\\\|//)[^\\\\/]+[\\\\/][^\\\\/]+(?:[\\\\/]|$)~D', $path) === 1;

        return DIRECTORY_SEPARATOR === '\\' ? $windowsAbsolute : $unixAbsolute;
    }

    private static function checkoutRoot(string $rootPath): string
    {
        $current = realpath($rootPath) ?: rtrim($rootPath, "\\/");
        $checkoutRoot = $current;
        do {
            if (file_exists($current . DIRECTORY_SEPARATOR . '.git')) {
                $checkoutRoot = $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        } while (true);

        return $checkoutRoot;
    }

    private static function isWithinRoot(string $path, string $rootPath): bool
    {
        $candidate = str_replace('\\', '/', realpath($path) ?: $path);
        $root = str_replace('\\', '/', realpath($rootPath) ?: $rootPath);
        $candidate = rtrim($candidate, '/');
        $root = rtrim($root, '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }
}
