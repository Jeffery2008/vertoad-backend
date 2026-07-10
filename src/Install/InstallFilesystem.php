<?php

declare(strict_types=1);

namespace VertoAD\Install;

final readonly class InstallFilesystem
{
    private \Closure $openFile;
    private \Closure $readFile;
    private \Closure $writeFile;
    private \Closure $removeFile;

    public function __construct(
        private string $rootPath,
        ?callable $openFile = null,
        ?callable $readFile = null,
        ?callable $writeFile = null,
        ?callable $removeFile = null,
    ) {
        $this->openFile = $openFile === null
            ? static fn (string $path, string $mode): mixed => @\fopen($path, $mode)
            : \Closure::fromCallable($openFile);
        $this->readFile = $readFile === null
            ? static fn (string $path): string|false => \file_get_contents($path)
            : \Closure::fromCallable($readFile);
        $this->writeFile = $writeFile === null
            ? static fn (mixed $handle, string $contents): int|false => \fwrite($handle, $contents)
            : \Closure::fromCallable($writeFile);
        $this->removeFile = $removeFile === null
            ? static fn (string $path): bool => @\unlink($path)
            : \Closure::fromCallable($removeFile);
    }

    public function synchronized(callable $operation): mixed
    {
        $storagePath = $this->rootPath . DIRECTORY_SEPARATOR . 'storage';
        $this->ensureDirectory($storagePath, 0700);
        $handle = ($this->openFile)($storagePath . DIRECTORY_SEPARATOR . '.installing.lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the installer process lock.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new InstallHttpException(409, 'installation_in_progress', 'Another installation request is already running.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, scalar|null> $environment
     * @param array{private_key: string, public_key: string} $keyPair
     * @param array<string, scalar|null> $lockMetadata
     */
    public function commitInstallation(
        array $environment,
        array $keyPair,
        array $lockMetadata,
        callable $commitDatabase,
    ): void {
        $keyPaths = $this->oauthKeyPaths();
        $paths = [
            $this->rootPath . DIRECTORY_SEPARATOR . '.env',
            $keyPaths['private_key_path'],
            $keyPaths['public_key_path'],
            $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock',
        ];
        $snapshots = $this->snapshotFiles($paths);

        try {
            $this->writeOAuthKeyPair($keyPair);
            $this->writeEnvironment($environment);
            $this->writePermanentLock($lockMetadata);
            $commitDatabase();
        } catch (\Throwable $exception) {
            try {
                $this->restoreFiles($snapshots);
            } catch (\Throwable $rollbackException) {
                throw new \RuntimeException(
                    'Installation failed and the previous filesystem state could not be fully restored.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /** @param array<string, scalar|null> $values */
    public function writeEnvironment(array $values): void
    {
        $environmentPath = $this->rootPath . DIRECTORY_SEPARATOR . '.env';
        $templatePath = $this->rootPath . DIRECTORY_SEPARATOR . '.env.example';
        $source = is_file($environmentPath)
            ? ($this->readFile)($environmentPath)
            : (is_file($templatePath) ? ($this->readFile)($templatePath) : '');
        if ($source === false) {
            throw new \RuntimeException('Unable to read the existing environment configuration.');
        }

        $this->atomicWrite($environmentPath, $this->mergeEnvironment($source, $values), 0600);
    }

    /** @param array{private_key: string, public_key: string} $keyPair */
    public function writeOAuthKeyPair(array $keyPair): array
    {
        $paths = $this->oauthKeyPaths();
        $directory = dirname($paths['private_key_path']);
        $this->ensureDirectory($directory, 0700);
        $this->atomicWrite($paths['private_key_path'], $keyPair['private_key'], 0600);
        $this->atomicWrite($paths['public_key_path'], $keyPair['public_key'], 0644);

        return [
            'private_key_path' => str_replace('\\', '/', $paths['private_key_path']),
            'public_key_path' => str_replace('\\', '/', $paths['public_key_path']),
        ];
    }

    /** @return array{private_key_path: string, public_key_path: string} */
    public function oauthKeyPaths(): array
    {
        $directory = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth';

        return [
            'private_key_path' => $directory . DIRECTORY_SEPARATOR . 'private.key',
            'public_key_path' => $directory . DIRECTORY_SEPARATOR . 'public.key',
        ];
    }

    /** @return array{private_key_path: string, public_key_path: string} */
    public function oauthEnvironmentKeyPaths(): array
    {
        return [
            'private_key_path' => 'storage/oauth/private.key',
            'public_key_path' => 'storage/oauth/public.key',
        ];
    }

    /** @param array<string, scalar|null> $metadata */
    public function writePermanentLock(array $metadata): void
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock';
        if (is_file($path)) {
            throw new InstallHttpException(410, 'already_installed', 'VertoAD has already been installed.');
        }

        $this->atomicWrite(
            $path,
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            0600,
        );
    }

    /** @param array<string, scalar|null> $values */
    private function mergeEnvironment(string $source, array $values): string
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new \InvalidArgumentException('Environment key is invalid: ' . $key);
            }
            $normalized[$key] = $this->encodeEnvironmentValue($value);
        }

        $trimmedSource = rtrim($source, "\r\n");
        $lines = $trimmedSource === '' ? [] : (preg_split('/\R/', $trimmedSource) ?: []);
        $rendered = [];
        $written = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) !== 1 || !array_key_exists($matches[1], $normalized)) {
                $rendered[] = $line;
                continue;
            }

            $key = $matches[1];
            if (!isset($written[$key])) {
                $rendered[] = $key . '=' . $normalized[$key];
                $written[$key] = true;
            }
        }

        foreach ($normalized as $key => $value) {
            if (!isset($written[$key])) {
                $rendered[] = $key . '=' . $value;
            }
        }

        return implode(PHP_EOL, $rendered) . PHP_EOL;
    }

    private function encodeEnvironmentValue(string|int|float|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\r" => '\\r',
            "\n" => '\\n',
        ]) . '"';
    }

    /**
     * @param list<string> $paths
     * @return array<string, array{exists: bool, contents: string, mode: int}>
     */
    private function snapshotFiles(array $paths): array
    {
        $snapshots = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                $snapshots[$path] = ['exists' => false, 'contents' => '', 'mode' => 0600];
                continue;
            }
            if (!is_file($path)) {
                throw new \RuntimeException('Installer target is not a regular file: ' . basename($path) . '.');
            }

            $contents = ($this->readFile)($path);
            if ($contents === false) {
                throw new \RuntimeException('Unable to snapshot installer file ' . basename($path) . '.');
            }
            $permissions = fileperms($path);
            $snapshots[$path] = [
                'exists' => true,
                'contents' => $contents,
                'mode' => $permissions === false ? 0600 : $permissions & 0777,
            ];
        }

        return $snapshots;
    }

    /** @param array<string, array{exists: bool, contents: string, mode: int}> $snapshots */
    private function restoreFiles(array $snapshots): void
    {
        foreach (array_reverse($snapshots, true) as $path => $snapshot) {
            if ($snapshot['exists']) {
                $this->atomicWrite($path, $snapshot['contents'], $snapshot['mode']);
                continue;
            }

            if ((is_file($path) || is_link($path)) && !($this->removeFile)($path)) {
                throw new \RuntimeException('Unable to remove staged installer file ' . basename($path) . '.');
            }
        }
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory, 0700);
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = ($this->openFile)($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create a temporary installer file.');
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = ($this->writeFile)($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Unable to write an installer file.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('Unable to flush an installer file.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            ($this->removeFile)($temporary);
            throw $exception;
        }

        fclose($handle);
        @chmod($temporary, $mode);
        if (!@rename($temporary, $path)) {
            ($this->removeFile)($temporary);
            throw new \RuntimeException('Unable to atomically replace ' . basename($path) . '.');
        }
        @chmod($path, $mode);
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create installer directory ' . basename($path) . '.');
        }
    }
}
