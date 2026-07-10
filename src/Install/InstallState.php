<?php

declare(strict_types=1);

namespace VertoAD\Install;

final readonly class InstallState
{
    public function __construct(private string $rootPath)
    {
    }

    public function isInstalled(): bool
    {
        if ($this->hasPermanentLock()) {
            return !$this->isInstallationInProgress();
        }

        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
        $testRunnerBypass = defined('PHPUNIT_COMPOSER_INSTALL')
            && filter_var(getenv('VERTOAD_INSTALL_TEST_BYPASS') ?: false, FILTER_VALIDATE_BOOL);

        return ($testRunnerBypass || in_array($environment, ['test', 'testing'], true))
            && filter_var(getenv('APP_INSTALLED') ?: false, FILTER_VALIDATE_BOOL);
    }

    public function hasPermanentLock(): bool
    {
        return is_file($this->lockPath());
    }

    public function lockPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    public function processLockPath(): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.installing.lock';
    }

    private function isInstallationInProgress(): bool
    {
        $path = $this->processLockPath();
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $available = flock($handle, LOCK_SH | LOCK_NB);
        if ($available) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$available;
    }
}
