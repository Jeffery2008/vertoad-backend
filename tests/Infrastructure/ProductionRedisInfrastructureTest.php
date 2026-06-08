<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Infrastructure\Security\RateLimitStoreInterface;
use VertoAD\Service\Cron\CronLockStoreInterface;

final class ProductionRedisInfrastructureTest extends TestCase
{
    public function testProductionCronLocksDoNotFallbackWhenRedisPasswordIsMissing(): void
    {
        $previous = $this->configureProductionWithoutRedisPassword();

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for cron lock storage.');

            $container?->get(CronLockStoreInterface::class);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    public function testProductionRateLimitsDoNotFallbackWhenRedisPasswordIsMissing(): void
    {
        $previous = $this->configureProductionWithoutRedisPassword();

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for rate limit storage.');

            $container?->get(RateLimitStoreInterface::class);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function configureProductionWithoutRedisPassword(): array
    {
        $previous = [
            'APP_KEY' => getenv('APP_KEY'),
            'APP_ENV' => getenv('APP_ENV'),
            'REDIS_PASSWORD' => getenv('REDIS_PASSWORD'),
            'REDIS_DRIVER' => getenv('REDIS_DRIVER'),
        ];

        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');
        putenv('REDIS_DRIVER=auto');

        return $previous;
    }

    /**
     * @param array<string, string|false> $previous
     */
    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }
    }
}
