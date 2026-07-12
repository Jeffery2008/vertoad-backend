<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\Cron\BackupCheckJob;
use VertoAD\Service\Operations\OperationsSummaryService;

final class BackupCheckJobTest extends TestCase
{
    public function testBackupCheckCronReportsHealthyOperationalEvidence(): void
    {
        $job = new BackupCheckJob(new OperationsSummaryService(
            backupStatus: [
                'status' => 'healthy',
                'last_backup_at' => '2026-06-09T01:00:00Z',
                'last_successful_backup_id' => 'backup_20260609_0100',
            ],
            restoreDrillEvidence: [
                'last_drill_at' => '2026-06-08T02:00:00Z',
                'evidence_url' => 's3://vertoad-backups/drills/2026-06-08.json',
                'verified_by' => 'ops-user-1',
            ],
            redisHardeningInventory: [
                'password_configured' => true,
                'dangerous_commands_disabled' => ['FLUSHALL', 'FLUSHDB', 'CONFIG'],
                'key_prefix' => 'vertoad:prod:',
                'prefix_collision_risk' => 'low',
                'auth_failure_alerting_configured' => true,
            ],
        ));

        $result = $job->run();

        self::assertSame('backup-check', $job->name());
        self::assertSame('backup-check', $result->jobName);
        self::assertSame('completed', $result->status);
        self::assertTrue($result->metrics['healthy'] ?? null);
        self::assertSame('healthy', $result->metrics['backup_status'] ?? null);
        self::assertSame('2026-06-09T01:00:00Z', $result->metrics['last_backup_at'] ?? null);
        self::assertSame('backup_20260609_0100', $result->metrics['last_successful_backup_id'] ?? null);
        self::assertSame('2026-06-08T02:00:00Z', $result->metrics['last_restore_drill_at'] ?? null);
        self::assertSame(0, $result->metrics['missing_controls'] ?? null);
        self::assertSame('Backup, restore drill, and Redis hardening evidence are healthy.', $result->message);
    }

    public function testBackupCheckCronReportsMissingOperationalEvidence(): void
    {
        $job = new BackupCheckJob(new OperationsSummaryService(
            backupStatus: [
                'status' => 'unknown',
                'last_backup_at' => '',
                'last_successful_backup_id' => null,
            ],
            restoreDrillEvidence: [
                'last_drill_at' => null,
            ],
            redisHardeningInventory: [
                'password_configured' => false,
                'dangerous_commands_disabled' => ['FLUSHALL'],
                'auth_failure_alerting_configured' => false,
            ],
        ));

        $result = $job->run();

        self::assertSame('failed', $result->status);
        self::assertFalse($result->metrics['healthy'] ?? true);
        self::assertSame('unknown', $result->metrics['backup_status'] ?? null);
        self::assertArrayHasKey('last_backup_at', $result->metrics);
        self::assertArrayHasKey('last_successful_backup_id', $result->metrics);
        self::assertArrayHasKey('last_restore_drill_at', $result->metrics);
        self::assertNull($result->metrics['last_backup_at']);
        self::assertNull($result->metrics['last_successful_backup_id']);
        self::assertNull($result->metrics['last_restore_drill_at']);
        self::assertSame(6, $result->metrics['missing_controls'] ?? null);
        self::assertSame(
            'Backup check found missing or unhealthy operational evidence: healthy_backup_status, successful_backup_evidence, restore_drill_evidence, redis_password, redis_dangerous_command_controls, redis_auth_failure_alerting.',
            $result->message,
        );
    }

}
