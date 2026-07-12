<?php

declare(strict_types=1);

namespace VertoAD\Install;

final readonly class InstallFilesystem
{
    private const ATOMIC_REPLACE_ATTEMPTS = 5;
    private const ATOMIC_REPLACE_DELAY_MICROSECONDS = 50_000;
    private const REMOVED_INSTALL_ENVIRONMENT_KEYS = ['OAUTH_PRIVATE_KEY_PASSPHRASE'];

    private string $environmentPath;
    private \Closure $openFile;
    private \Closure $readFile;
    private \Closure $writeFile;
    private \Closure $removeFile;
    private \Closure $changeMode;
    private \Closure $replaceFile;
    private \Closure $delay;

    public function __construct(
        private string $rootPath,
        ?string $environmentPath = null,
        ?callable $openFile = null,
        ?callable $readFile = null,
        ?callable $writeFile = null,
        ?callable $removeFile = null,
        ?callable $changeMode = null,
        ?callable $replaceFile = null,
        ?callable $delay = null,
    ) {
        $this->environmentPath = $environmentPath
            ?? $this->rootPath . DIRECTORY_SEPARATOR . '.env';
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
        $this->changeMode = $changeMode === null
            ? static fn (string $path, int $mode): bool => @\chmod($path, $mode)
            : \Closure::fromCallable($changeMode);
        $this->replaceFile = $replaceFile === null
            ? static fn (string $source, string $target): bool => @\rename($source, $target)
            : \Closure::fromCallable($replaceFile);
        $this->delay = $delay === null
            ? static fn (int $microseconds): null => \usleep($microseconds)
            : \Closure::fromCallable($delay);
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
        if (!array_key_exists('INSTALL_TOKEN', $environment) || $environment['INSTALL_TOKEN'] !== '') {
            throw new \InvalidArgumentException('A completed installation must clear INSTALL_TOKEN.');
        }

        $keyPaths = $this->oauthKeyPaths();
        $paths = [
            $this->environmentPath,
            $keyPaths['private_key_path'],
            $keyPaths['public_key_path'],
            $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock',
        ];
        $snapshots = $this->snapshotFiles($paths);

        try {
            $this->writeOAuthKeyPair($keyPair);
            $this->writeEnvironmentConfiguration($environment, self::REMOVED_INSTALL_ENVIRONMENT_KEYS);
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
        $this->writeEnvironmentConfiguration($values);
    }

    /**
     * @param array<string, scalar|null> $values
     * @param list<string> $removedKeys
     */
    private function writeEnvironmentConfiguration(array $values, array $removedKeys = []): void
    {
        $templatePath = $this->rootPath . DIRECTORY_SEPARATOR . '.env.example';
        $source = is_file($this->environmentPath)
            ? ($this->readFile)($this->environmentPath)
            : (is_file($templatePath) ? ($this->readFile)($templatePath) : '');
        if ($source === false) {
            throw new \RuntimeException('Unable to read the existing environment configuration.');
        }

        $this->atomicWrite(
            $this->environmentPath,
            $this->mergeEnvironment($source, $values, $removedKeys),
            0600,
        );
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

    /**
     * @param array<string, scalar|null> $values
     * @param list<string> $removedKeys
     */
    private function mergeEnvironment(string $source, array $values, array $removedKeys): string
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new \InvalidArgumentException('Environment key is invalid: ' . $key);
            }
            $normalized[$key] = $this->encodeEnvironmentValue($value);
        }
        $removed = array_fill_keys(array_map('strtoupper', $removedKeys), true);
        foreach ($removed as $key => $_) {
            unset($normalized[$key]);
        }

        $trimmedSource = rtrim($source, "\r\n");
        $lines = $trimmedSource === '' ? [] : (preg_split('/\R/', $trimmedSource) ?: []);
        $rendered = [];
        $written = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/i', $line, $matches) !== 1) {
                $rendered[] = $line;
                continue;
            }

            $key = strtoupper($matches[1]);
            if (isset($removed[$key])) {
                continue;
            }
            if (!array_key_exists($key, $normalized)) {
                $rendered[] = $line;
                continue;
            }
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
        // A same-directory temporary file keeps replacement on one volume and inherits the directory ACL on Windows.
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = ($this->openFile)($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create a temporary installer file.');
        }
        try {
            $permissionsSecured = ($this->changeMode)($temporary, $mode);
        } catch (\Throwable $exception) {
            fclose($handle);
            $this->discardTemporaryFile($temporary, $exception);
        }
        if (!$permissionsSecured) {
            fclose($handle);
            $this->discardTemporaryFile(
                $temporary,
                new \RuntimeException('Unable to secure temporary installer file permissions.'),
            );
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
            $this->discardTemporaryFile($temporary, $exception);
        }

        fclose($handle);
        try {
            for ($attempt = 1; $attempt <= self::ATOMIC_REPLACE_ATTEMPTS; $attempt++) {
                if (($this->replaceFile)($temporary, $path)) {
                    return;
                }
                if ($attempt < self::ATOMIC_REPLACE_ATTEMPTS) {
                    ($this->delay)(self::ATOMIC_REPLACE_DELAY_MICROSECONDS);
                }
            }
        } catch (\Throwable $exception) {
            $this->discardTemporaryFile($temporary, $exception);
        }

        $this->discardTemporaryFile(
            $temporary,
            new \RuntimeException('Unable to atomically replace ' . basename($path) . '.'),
        );
    }

    private function discardTemporaryFile(string $path, \Throwable $failure): never
    {
        try {
            $removed = (!is_file($path) && !is_link($path)) || ($this->removeFile)($path);
        } catch (\Throwable) {
            $removed = false;
        }
        if (!$removed) {
            throw new \RuntimeException(
                'Installer file update failed and its temporary file could not be removed.',
                0,
                $failure,
            );
        }

        throw $failure;
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create installer directory ' . basename($path) . '.');
        }
    }
}
