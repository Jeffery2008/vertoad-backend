<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Service\Cron\RedisCronLockStore;

final class RedisCronLockStoreNoExtensionTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testFactoryReportsMissingRedisExtensionWhenPhpRedisDriverIsForced(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('The Redis extension is available in this process.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Redis extension is required for phpredis connections.');

        RedisCronLockStore::fromSettings(['driver' => 'phpredis', 'password' => 'secret']);
    }
}
