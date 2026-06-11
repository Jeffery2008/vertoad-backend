<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Budget\SpendReservation;
use VertoAD\Domain\Budget\SpendReservationStatus;
use VertoAD\Domain\Budget\SpendReservationTransition;

final class CampaignBudgetRepository implements CampaignBudgetRepositoryInterface
{
    private ?bool $hasBudgetLockTables = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function saveCaps(CampaignBudgetCaps $caps): CampaignBudgetCaps
    {
        $existing = $this->findCaps($caps->organizationId, $caps->campaignId);
        $values = [
            'campaign_id' => $caps->campaignId,
            'organization_id' => $caps->organizationId,
            'total_cap_points' => $caps->totalCapPoints,
            'daily_cap_points' => $caps->dailyCapPoints,
            'hourly_cap_points' => $caps->hourlyCapPoints,
        ];
        $types = [
            'campaign_id' => ParameterType::INTEGER,
            'organization_id' => ParameterType::INTEGER,
            'total_cap_points' => $caps->totalCapPoints === null ? ParameterType::NULL : ParameterType::INTEGER,
            'daily_cap_points' => $caps->dailyCapPoints === null ? ParameterType::NULL : ParameterType::INTEGER,
            'hourly_cap_points' => $caps->hourlyCapPoints === null ? ParameterType::NULL : ParameterType::INTEGER,
        ];

        if ($existing === null) {
            $this->connection->insert('campaign_budget_caps', $values, $types);
        } else {
            $this->connection->update(
                'campaign_budget_caps',
                array_diff_key($values, ['campaign_id' => true]),
                ['campaign_id' => $caps->campaignId],
                array_diff_key($types, ['campaign_id' => true]),
            );
        }

        return $caps;
    }

    public function findCaps(int $organizationId, int $campaignId): ?CampaignBudgetCaps
    {
        if ($organizationId <= 0 || $campaignId <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('campaign_id', 'organization_id', 'total_cap_points', 'daily_cap_points', 'hourly_cap_points')
            ->from('campaign_budget_caps')
            ->where('organization_id = :organization_id')
            ->andWhere('campaign_id = :campaign_id')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('campaign_id', $campaignId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateCaps($row);
    }

    public function findReservation(string $reservationId): ?SpendReservation
    {
        $reservationId = trim($reservationId);
        if ($reservationId === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'reservation_id',
                'organization_id',
                'campaign_id',
                'points_amount',
                'status',
                'reserved_at',
                'expires_at',
                'committed_at',
                'released_at',
                'expired_at',
                'ledger_entry_id',
            )
            ->from('spend_reservations')
            ->where('reservation_id = :reservation_id')
            ->setParameter('reservation_id', $reservationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateReservation($row);
    }

    public function lockBudgetScope(int $organizationId, int $campaignId): void
    {
        if ($organizationId <= 0 || $campaignId <= 0 || !$this->hasBudgetLockTables()) {
            return;
        }

        $this->ensureOrganizationBudgetLock($organizationId);
        $this->ensureCampaignBudgetLock($organizationId, $campaignId);
        if (!$this->supportsForUpdate()) {
            return;
        }

        $this->connection->fetchOne(
            'SELECT organization_id FROM organization_budget_locks WHERE organization_id = ? FOR UPDATE',
            [$organizationId],
        );
        $this->connection->fetchOne(
            'SELECT campaign_id FROM campaign_budget_locks WHERE organization_id = ? AND campaign_id = ? FOR UPDATE',
            [$organizationId, $campaignId],
        );
    }

    public function createReservation(SpendReservation $reservation): SpendReservation
    {
        try {
            $this->connection->insert(
                'spend_reservations',
                [
                    'reservation_id' => $reservation->reservationId,
                    'organization_id' => $reservation->organizationId,
                    'campaign_id' => $reservation->campaignId,
                    'points_amount' => $reservation->pointsAmount,
                    'status' => $reservation->status->value,
                    'reserved_at' => $this->formatDate($reservation->reservedAt),
                    'expires_at' => $this->formatDate($reservation->expiresAt),
                    'committed_at' => null,
                    'released_at' => null,
                    'expired_at' => null,
                    'ledger_entry_id' => null,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Idempotency is keyed by reservation_id; concurrent retries should see the existing row.
        }

        return $this->findReservation($reservation->reservationId) ?? $reservation;
    }

    public function markCommitted(string $reservationId, int $ledgerEntryId, DateTimeImmutable $committedAt): SpendReservationTransition
    {
        $changed = $this->connection->update(
            'spend_reservations',
            [
                'status' => SpendReservationStatus::Committed->value,
                'committed_at' => $this->formatDate($committedAt),
                'ledger_entry_id' => $ledgerEntryId,
            ],
            [
                'reservation_id' => trim($reservationId),
                'status' => SpendReservationStatus::Reserved->value,
            ],
        );

        return new SpendReservationTransition($changed === 1, $this->findReservation($reservationId));
    }

    public function markReleased(string $reservationId, DateTimeImmutable $releasedAt): SpendReservationTransition
    {
        $changed = $this->connection->update(
            'spend_reservations',
            ['status' => SpendReservationStatus::Released->value, 'released_at' => $this->formatDate($releasedAt)],
            [
                'reservation_id' => trim($reservationId),
                'status' => SpendReservationStatus::Reserved->value,
            ],
        );

        return new SpendReservationTransition($changed === 1, $this->findReservation($reservationId));
    }

    public function markExpired(string $reservationId, DateTimeImmutable $expiredAt): SpendReservationTransition
    {
        $changed = $this->connection->update(
            'spend_reservations',
            ['status' => SpendReservationStatus::Expired->value, 'expired_at' => $this->formatDate($expiredAt)],
            [
                'reservation_id' => trim($reservationId),
                'status' => SpendReservationStatus::Reserved->value,
            ],
        );

        return new SpendReservationTransition($changed === 1, $this->findReservation($reservationId));
    }

    public function activeReservedSpendForCampaign(int $organizationId, int $campaignId, DateTimeImmutable $at): int
    {
        return $this->sumReservations(
            'organization_id = :organization_id AND campaign_id = :campaign_id AND status = :status AND expires_at > :at',
            ['organization_id' => $organizationId, 'campaign_id' => $campaignId, 'status' => 'reserved', 'at' => $this->formatDate($at)],
        );
    }

    public function committedSpendForCampaign(int $organizationId, int $campaignId): int
    {
        return $this->sumReservations(
            'organization_id = :organization_id AND campaign_id = :campaign_id AND status = :status',
            ['organization_id' => $organizationId, 'campaign_id' => $campaignId, 'status' => 'committed'],
        );
    }

    public function activeReservedSpendForCampaignWindow(
        int $organizationId,
        int $campaignId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeImmutable $at,
    ): int {
        return $this->sumReservations(
            'organization_id = :organization_id AND campaign_id = :campaign_id AND status = :status'
                . ' AND expires_at > :at AND reserved_at >= :window_start AND reserved_at < :window_end',
            [
                'organization_id' => $organizationId,
                'campaign_id' => $campaignId,
                'status' => 'reserved',
                'at' => $this->formatDate($at),
                'window_start' => $this->formatDate($windowStart),
                'window_end' => $this->formatDate($windowEnd),
            ],
        );
    }

    public function committedSpendForCampaignWindow(
        int $organizationId,
        int $campaignId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): int {
        return $this->sumReservations(
            'organization_id = :organization_id AND campaign_id = :campaign_id AND status = :status'
                . ' AND reserved_at >= :window_start AND reserved_at < :window_end',
            [
                'organization_id' => $organizationId,
                'campaign_id' => $campaignId,
                'status' => 'committed',
                'window_start' => $this->formatDate($windowStart),
                'window_end' => $this->formatDate($windowEnd),
            ],
        );
    }

    public function activeReservedSpendForOrganization(int $organizationId, DateTimeImmutable $at): int
    {
        return $this->sumReservations(
            'organization_id = :organization_id AND status = :status AND expires_at > :at',
            ['organization_id' => $organizationId, 'status' => 'reserved', 'at' => $this->formatDate($at)],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function sumReservations(string $where, array $parameters): int
    {
        $query = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(points_amount), 0)')
            ->from('spend_reservations')
            ->where($where);

        foreach ($parameters as $key => $value) {
            $query->setParameter($key, $value);
        }

        return (int) $query->fetchOne();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCaps(array $row): CampaignBudgetCaps
    {
        return new CampaignBudgetCaps(
            campaignId: (int) $row['campaign_id'],
            organizationId: (int) $row['organization_id'],
            totalCapPoints: $row['total_cap_points'] === null ? null : (int) $row['total_cap_points'],
            dailyCapPoints: $row['daily_cap_points'] === null ? null : (int) $row['daily_cap_points'],
            hourlyCapPoints: $row['hourly_cap_points'] === null ? null : (int) $row['hourly_cap_points'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateReservation(array $row): SpendReservation
    {
        return new SpendReservation(
            id: (int) $row['id'],
            reservationId: (string) $row['reservation_id'],
            organizationId: (int) $row['organization_id'],
            campaignId: (int) $row['campaign_id'],
            pointsAmount: (int) $row['points_amount'],
            status: SpendReservationStatus::from((string) $row['status']),
            reservedAt: new DateTimeImmutable((string) $row['reserved_at']),
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            committedAt: $row['committed_at'] === null ? null : new DateTimeImmutable((string) $row['committed_at']),
            releasedAt: $row['released_at'] === null ? null : new DateTimeImmutable((string) $row['released_at']),
            expiredAt: $row['expired_at'] === null ? null : new DateTimeImmutable((string) $row['expired_at']),
            ledgerEntryId: $row['ledger_entry_id'] === null ? null : (int) $row['ledger_entry_id'],
        );
    }

    private function formatDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }

    private function ensureOrganizationBudgetLock(int $organizationId): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT organization_id FROM organization_budget_locks WHERE organization_id = ?',
            [$organizationId],
        );
        if ($exists !== false && $exists !== null) {
            return;
        }

        if ($this->isSqlite()) {
            $this->connection->executeStatement(
                'INSERT OR IGNORE INTO organization_budget_locks (organization_id, updated_at) VALUES (?, ?)',
                [$organizationId, $this->formatDate(new DateTimeImmutable())],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO organization_budget_locks (organization_id, updated_at) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE updated_at = updated_at',
            [$organizationId, $this->formatDate(new DateTimeImmutable())],
        );
    }

    private function ensureCampaignBudgetLock(int $organizationId, int $campaignId): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT campaign_id FROM campaign_budget_locks WHERE organization_id = ? AND campaign_id = ?',
            [$organizationId, $campaignId],
        );
        if ($exists !== false && $exists !== null) {
            return;
        }

        if ($this->isSqlite()) {
            $this->connection->executeStatement(
                'INSERT OR IGNORE INTO campaign_budget_locks (organization_id, campaign_id, updated_at) VALUES (?, ?, ?)',
                [$organizationId, $campaignId, $this->formatDate(new DateTimeImmutable())],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO campaign_budget_locks (organization_id, campaign_id, updated_at) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE updated_at = updated_at',
            [$organizationId, $campaignId, $this->formatDate(new DateTimeImmutable())],
        );
    }

    private function hasBudgetLockTables(): bool
    {
        if ($this->hasBudgetLockTables !== null) {
            return $this->hasBudgetLockTables;
        }

        try {
            $this->hasBudgetLockTables = $this->connection->createSchemaManager()->tablesExist([
                'organization_budget_locks',
                'campaign_budget_locks',
            ]);
        } catch (\Throwable) {
            $this->hasBudgetLockTables = false;
        }

        return $this->hasBudgetLockTables;
    }

    private function supportsForUpdate(): bool
    {
        return !$this->isSqlite();
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
