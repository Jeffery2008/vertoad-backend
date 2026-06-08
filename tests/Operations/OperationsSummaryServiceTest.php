<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;

final class OperationsSummaryServiceTest extends TestCase
{
    public function testSummaryExposesBackupRestoreDrillAndRedisHardeningInventory(): void
    {
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationsSummaryService';
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(
            backupStatus: [
                'status' => 'healthy',
                'last_backup_at' => '2026-06-08T01:00:00Z',
                'last_successful_backup_id' => 'backup_20260608_0100',
            ],
            restoreDrillEvidence: [
                'last_drill_at' => '2026-06-07T02:00:00Z',
                'evidence_url' => 's3://vertoad-backups/drills/2026-06-07.json',
                'verified_by' => 'ops-user-1',
            ],
            redisHardeningInventory: [
                'password_configured' => true,
                'dangerous_commands_disabled' => ['FLUSHALL', 'FLUSHDB', 'CONFIG'],
                'key_prefix' => 'vertoad:prod:',
                'prefix_collision_risk' => 'low',
                'auth_failure_alerting_configured' => true,
            ],
        );

        $summary = $service->summary();

        self::assertSame('healthy', $this->value($this->value($summary, 'backup_status'), 'status'));
        self::assertSame('backup_20260608_0100', $this->value($this->value($summary, 'backup_status'), 'last_successful_backup_id'));
        self::assertSame('2026-06-07T02:00:00Z', $this->value($this->value($summary, 'restore_drill_evidence'), 'last_drill_at'));
        self::assertTrue($this->value($this->value($summary, 'redis_hardening_inventory'), 'password_configured'));
        self::assertSame(['FLUSHALL', 'FLUSHDB', 'CONFIG'], $this->value($this->value($summary, 'redis_hardening_inventory'), 'dangerous_commands_disabled'));
        self::assertSame('vertoad:prod:', $this->value($this->value($summary, 'redis_hardening_inventory'), 'key_prefix'));
        self::assertSame('low', $this->value($this->value($summary, 'redis_hardening_inventory'), 'prefix_collision_risk'));
        self::assertTrue($this->value($this->value($summary, 'redis_hardening_inventory'), 'auth_failure_alerting_configured'));
    }

    public function testAppFactoryMergesRedisHardeningInventoryFromEnvironment(): void
    {
        $previous = $this->configureRedisHardeningEnvironment();

        try {
            $container = AppFactory::create()->getContainer();
            $service = $container?->get('VertoAD\\Service\\Operations\\OperationsSummaryService');

            self::assertIsObject($service);
            self::assertTrue(method_exists($service, 'summary'));

            $inventory = $this->value($service->summary(), 'redis_hardening_inventory');

            self::assertTrue($this->value($inventory, 'password_configured'));
            self::assertSame(['FLUSHALL', 'FLUSHDB', 'CONFIG'], $this->value($inventory, 'dangerous_commands_disabled'));
            self::assertSame('vertoad:staging:', $this->value($inventory, 'key_prefix'));
            self::assertSame('unknown', $this->value($inventory, 'prefix_collision_risk'));
            self::assertTrue($this->value($inventory, 'auth_failure_alerting_configured'));
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function configureRedisHardeningEnvironment(): array
    {
        $previous = [
            'APP_KEY' => getenv('APP_KEY'),
            'REDIS_PASSWORD' => getenv('REDIS_PASSWORD'),
            'REDIS_PREFIX' => getenv('REDIS_PREFIX'),
            'REDIS_DANGEROUS_COMMANDS_DISABLED' => getenv('REDIS_DANGEROUS_COMMANDS_DISABLED'),
            'REDIS_AUTH_FAILURE_ALERTING_CONFIGURED' => getenv('REDIS_AUTH_FAILURE_ALERTING_CONFIGURED'),
        ];

        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('REDIS_PASSWORD=unit-test-long-random-password');
        putenv('REDIS_PREFIX=vertoad:staging:');
        putenv('REDIS_DANGEROUS_COMMANDS_DISABLED=flushall, flushdb, config');
        putenv('REDIS_AUTH_FAILURE_ALERTING_CONFIGURED=true');

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

    private function value(mixed $record, string $key): mixed
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        if (is_object($record)) {
            return $record->{$key} ?? null;
        }

        return null;
    }
}
