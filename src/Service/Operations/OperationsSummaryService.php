<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;

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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'backup_status' => $this->backupStatus,
            'restore_drill_evidence' => $this->restoreDrillEvidence,
            'redis_hardening_inventory' => $this->redisHardeningInventory,
            'audit_on_view' => [
                'raw_context' => true,
                'config_rollback' => true,
            ],
            'ip_geo_queue' => $this->ipGeoRepository?->lookupQueueSummary() ?? $this->emptyIpGeoQueueSummary(),
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
