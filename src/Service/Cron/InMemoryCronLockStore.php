<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

final class InMemoryCronLockStore implements CronLockStoreInterface
{
    /** @var array<string, int> */
    private array $locks = [];

    public function acquire(string $lockKey, int $ttlSeconds): bool
    {
        $this->purgeExpired();
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Cron lock TTL seconds must be positive.');
        }

        if (isset($this->locks[$lockKey])) {
            return false;
        }

        $this->locks[$lockKey] = time() + $ttlSeconds;

        return true;
    }

    public function isLocked(string $lockKey): bool
    {
        $this->purgeExpired();

        return isset($this->locks[$lockKey]);
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->locks as $key => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->locks[$key]);
            }
        }
    }
}
