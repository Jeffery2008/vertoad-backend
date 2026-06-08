<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\Serving\RedisAdEventRepository;

final class RedisAdEventRepositoryNoExtensionTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testFactoryReportsMissingRedisExtensionWhenPhpRedisDriverIsForced(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('The Redis extension is available in this process.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Redis extension is required for phpredis connections.');

        RedisAdEventRepository::fromSettings(['driver' => 'phpredis', 'password' => 'secret']);
    }
}
