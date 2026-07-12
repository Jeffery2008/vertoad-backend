<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;

final class ProductionServingEventBufferTest extends TestCase
{
    public function testProductionServingEventBufferDoesNotFallbackWhenRedisPasswordIsMissing(): void
    {
        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        $previousEnvironmentFile = getenv(EnvironmentLoader::ENV_FILE_VARIABLE);
        $environmentFile = tempnam(sys_get_temp_dir(), 'vertoad-serving-env-');
        if ($environmentFile === false || file_put_contents($environmentFile, '') === false) {
            throw new \RuntimeException('Unable to initialize a temporary external environment file.');
        }
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');
        putenv(EnvironmentLoader::ENV_FILE_VARIABLE . '=' . $environmentFile);

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for serving event buffering.');

            $container?->get(AdEventRepositoryInterface::class);
        } finally {
            $this->restoreEnv('APP_KEY', $previousAppKey);
            $this->restoreEnv('APP_ENV', $previousAppEnv);
            $this->restoreEnv('REDIS_PASSWORD', $previousRedisPassword);
            $this->restoreEnv(EnvironmentLoader::ENV_FILE_VARIABLE, $previousEnvironmentFile);
            @unlink($environmentFile);
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
