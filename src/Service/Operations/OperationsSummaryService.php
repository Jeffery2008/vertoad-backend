<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

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
        ];
    }
}
