<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Security\RedisRateLimitStore;

final class RedisRateLimitStoreNoExtensionTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testFactoryReportsMissingRedisExtensionWhenPhpRedisDriverIsForced(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('The Redis extension is available in this process.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Redis extension is required for phpredis connections.');

        RedisRateLimitStore::fromSettings(['driver' => 'phpredis', 'password' => 'secret']);
    }
}
