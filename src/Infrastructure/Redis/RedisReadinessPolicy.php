<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

final class RedisReadinessPolicy
{
    public const string MINIMUM_VERSION = '8.8.0';
    public const int MINIMUM_PASSWORD_LENGTH = 32;

    /** @var list<string> */
    public const array DANGEROUS_COMMANDS = ['FLUSHALL', 'FLUSHDB', 'CONFIG'];

    /**
     * @param array<string, bool|null> $dangerousCommandAccess
     * @return list<string>
     */
    public function assess(string $password, string $version, array $dangerousCommandAccess): array
    {
        $version = trim($version);
        $issues = [];
        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $issues[] = 'redis_password_too_short';
        }

        if (!$this->isVersion($version)) {
            $issues[] = 'redis_version_unverifiable';
        } elseif (version_compare($version, self::MINIMUM_VERSION, '<')) {
            $issues[] = 'redis_version_too_old';
        }

        foreach (self::DANGEROUS_COMMANDS as $command) {
            $allowed = $dangerousCommandAccess[$command] ?? null;
            if ($allowed === null) {
                $issues[] = 'redis_acl_unverifiable:' . $command;
            } elseif ($allowed) {
                $issues[] = 'redis_dangerous_command_allowed:' . $command;
            }
        }

        return $issues;
    }

    public function interpretAclDryRun(string $command, ?string $result, ?string $errorMessage): ?bool
    {
        if ($errorMessage === null && strtoupper(trim((string) $result)) === 'OK') {
            return true;
        }

        $message = strtolower(trim($errorMessage ?? (string) $result));
        $target = strtolower(trim($command));
        if (!str_contains($message, 'no permissions')) {
            return null;
        }

        return str_contains($message, "'{$target}'") || str_contains($message, "'{$target}|")
            ? false
            : null;
    }

    private function isVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', trim($version)) === 1;
    }
}
