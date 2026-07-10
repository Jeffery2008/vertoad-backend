<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;

final readonly class OperationsSummaryService
{
    /**
     * @param array<string, mixed> $backupStatus
     * @param array<string, mixed> $restoreDrillEvidence
     * @param array<string, mixed> $redisHardeningInventory
     */
    public function __construct(
        private array $backupStatus,
        private array $restoreDrillEvidence,
        private array $redisHardeningInventory,
        private ?IpGeoRepositoryInterface $ipGeoRepository = null,
        private ?BackupJobRepositoryInterface $backupJobs = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'backup_status' => $this->currentBackupStatus(),
            'restore_drill_evidence' => $this->currentRestoreEvidence(),
            'redis_hardening_inventory' => $this->redisHardeningInventory,
            'audit_on_view' => [
                'raw_context' => true,
                'config_rollback' => true,
                'backup_restore' => true,
            ],
            'ip_geo_queue' => $this->ipGeoRepository?->lookupQueueSummary() ?? $this->emptyIpGeoQueueSummary(),
        ];
    }

    /** @return array<string, mixed> */
    private function currentBackupStatus(): array
    {
        if ($this->backupJobs === null) {
            return $this->backupStatus;
        }
        $completed = $this->backupJobs->latestCompleted('backup');
        if ($completed !== null) {
            return [
                'status' => 'healthy',
                'last_backup_at' => $completed->completedAt?->format(DATE_ATOM),
                'last_successful_backup_id' => $completed->jobId,
            ];
        }
        $latest = $this->backupJobs->list('backup', 1, 0)['items'][0] ?? null;

        return [
            'status' => $latest === null ? 'unknown' : ($latest->status === 'failed' ? 'unhealthy' : 'pending'),
            'last_backup_at' => null,
            'last_successful_backup_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function currentRestoreEvidence(): array
    {
        if ($this->backupJobs === null) {
            return $this->restoreDrillEvidence;
        }
        $completed = $this->backupJobs->latestCompleted('restore');

        return [
            'last_drill_at' => $completed?->completedAt?->format(DATE_ATOM),
            'evidence_url' => $completed?->evidenceObjectKey,
            'verified_by' => $completed?->requestedByUserId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyIpGeoQueueSummary(): array
    {
        return [
            'counts' => [
                'pending' => 0,
                'processing' => 0,
                'failed' => 0,
                'dead' => 0,
                'resolved' => 0,
                'total' => 0,
            ],
            'oldest_pending_at' => null,
            'next_retry_at' => null,
            'latest_failure' => null,
            'recent_tasks' => [],
        ];
    }
}
