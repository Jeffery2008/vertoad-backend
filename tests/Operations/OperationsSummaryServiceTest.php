<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;

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
