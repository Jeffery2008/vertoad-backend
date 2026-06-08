<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;

final class ProductionServingEventBufferTest extends TestCase
{
    public function testProductionServingEventBufferDoesNotFallbackWhenRedisPasswordIsMissing(): void
    {
        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for serving event buffering.');

            $container?->get(AdEventRepositoryInterface::class);
        } finally {
            $this->restoreEnv('APP_KEY', $previousAppKey);
            $this->restoreEnv('APP_ENV', $previousAppEnv);
            $this->restoreEnv('REDIS_PASSWORD', $previousRedisPassword);
        }
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}
