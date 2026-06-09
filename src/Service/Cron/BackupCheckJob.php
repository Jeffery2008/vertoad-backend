<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Operations\OperationsSummaryService;

final readonly class BackupCheckJob implements CronJobInterface
{
    public function __construct(private OperationsSummaryService $operations)
    {
    }

    public function name(): string
    {
        return 'backup-check';
    }

    public function run(): CronJobResult
    {
        $summary = $this->operations->summary();
        $backupStatus = $summary['backup_status'] ?? [];
        $restoreDrill = $summary['restore_drill_evidence'] ?? [];
        $redisHardening = $summary['redis_hardening_inventory'] ?? [];
        $status = (string) ($backupStatus['status'] ?? 'unknown');
        $lastBackupAt = $this->nullableString($backupStatus['last_backup_at'] ?? null);
        $lastBackupId = $this->nullableString($backupStatus['last_successful_backup_id'] ?? null);
        $lastDrillAt = $this->nullableString($restoreDrill['last_drill_at'] ?? null);
        $dangerousCommandsDisabled = $redisHardening['dangerous_commands_disabled'] ?? [];
        $missing = [];

        if ($status !== 'healthy') {
            $missing[] = 'healthy_backup_status';
        }
        if ($lastBackupAt === null || $lastBackupId === null) {
            $missing[] = 'successful_backup_evidence';
        }
        if ($lastDrillAt === null) {
            $missing[] = 'restore_drill_evidence';
        }
        if (($redisHardening['password_configured'] ?? false) !== true) {
            $missing[] = 'redis_password';
        }
        if (!is_array($dangerousCommandsDisabled) || $dangerousCommandsDisabled === []) {
            $missing[] = 'redis_dangerous_command_controls';
        }
        if (($redisHardening['auth_failure_alerting_configured'] ?? false) !== true) {
            $missing[] = 'redis_auth_failure_alerting';
        }

        return CronJobResult::completed(
            $this->name(),
            [
                'healthy' => $missing === [],
                'backup_status' => $status,
                'last_backup_at' => $lastBackupAt,
                'last_successful_backup_id' => $lastBackupId,
                'last_restore_drill_at' => $lastDrillAt,
                'missing_controls' => count($missing),
            ],
            $missing === []
                ? 'Backup, restore drill, and Redis hardening evidence are healthy.'
                : 'Backup check found missing or unhealthy operational evidence: ' . implode(', ', $missing) . '.',
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
