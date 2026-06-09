<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

interface CronLockStoreInterface
{
    public function acquire(string $lockKey, int $ttlSeconds): bool;

    public function release(string $lockKey): void;

    public function isLocked(string $lockKey): bool;
}
