<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use RuntimeException;
use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingClaim;
use VertoAD\Domain\Billing\CpmBillingStream;
use VertoAD\Domain\Billing\CpmRevenueShareSnapshot;

final readonly class DatabaseCpmBillingRepository implements CpmBillingRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function findAllocation(string $eventKey): ?CpmBillingAllocation
    {
        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(...$this->allocationColumns())
            ->from('cpm_billing_event_allocations')
            ->where('event_key = :event_key')
            ->setParameter('event_key', $eventKey)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateAllocation($row);
    }

    public function findAccumulator(CpmBillingStream $stream): ?CpmBillingAccumulator
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->accumulatorColumns())
            ->from('cpm_billing_accumulators')
            ->where('stream_key = :stream_key')
            ->setParameter('stream_key', $stream->key())
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateAccumulator($row);
    }

    public function lockAccumulator(CpmBillingStream $stream): CpmBillingAccumulator
    {
        $streamKey = $stream->key();
        $this->ensureAccumulator($stream);

        $sql = 'SELECT ' . implode(', ', $this->accumulatorColumns())
            . ' FROM cpm_billing_accumulators WHERE stream_key = ?';
        if ($this->supportsForUpdate()) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, [$streamKey]);
        if ($row === false) {
            throw new RuntimeException('CPM accumulator row could not be loaded after creation.');
        }

        return $this->hydrateAccumulator($row);
    }

    public function saveAccumulator(CpmBillingAccumulator $accumulator): CpmBillingAccumulator
    {
        if ($accumulator->version === PHP_INT_MAX) {
            throw new RuntimeException('CPM accumulator version has reached its integer limit.');
        }

        $nextVersion = $accumulator->version + 1;
        $updatedAt = $this->nowSql();
        $changed = $this->connection->update(
            'cpm_billing_accumulators',
            [
                'gross_remainder_milli_points' => $accumulator->grossRemainderMilliPoints,
                'publisher_share_remainder_numerator' => $accumulator->publisherShareRemainderNumerator,
                'impression_count' => $accumulator->impressionCount,
                'billed_points' => $accumulator->billedPoints,
                'publisher_points' => $accumulator->publisherPoints,
                'version' => $nextVersion,
                'updated_at' => $updatedAt,
            ],
            [
                'stream_key' => $accumulator->stream->key(),
                'version' => $accumulator->version,
            ],
            [
                'gross_remainder_milli_points' => ParameterType::INTEGER,
                'publisher_share_remainder_numerator' => ParameterType::INTEGER,
                'impression_count' => ParameterType::INTEGER,
                'billed_points' => ParameterType::INTEGER,
                'publisher_points' => ParameterType::INTEGER,
                'version' => ParameterType::INTEGER,
                'updated_at' => ParameterType::STRING,
                'stream_key' => ParameterType::STRING,
            ],
        );
        if ($changed !== 1) {
            throw new RuntimeException('CPM accumulator version conflict.');
        }

        return $accumulator->withVersion($nextVersion, new DateTimeImmutable($updatedAt, new DateTimeZone('UTC')));
    }

    public function claimAllocation(CpmBillingAllocation $allocation): CpmBillingClaim
    {
        if ($allocation->status !== CpmBillingAllocationStatus::Processing) {
            throw new RuntimeException('CPM allocation claim must have processing status.');
        }

        try {
            $this->connection->insert('cpm_billing_event_allocations', [
                'event_key' => $allocation->eventKey,
                'event_type' => $allocation->eventType,
                'event_id' => $allocation->eventId,
                'decision_id' => $allocation->decisionId,
                'stream_key' => $allocation->stream->key(),
                'accumulator_id' => $allocation->accumulatorId,
                'advertiser_organization_id' => $allocation->stream->advertiserOrganizationId,
                'campaign_id' => $allocation->stream->campaignId,
                'publisher_organization_id' => $allocation->stream->publisherOrganizationId,
                'site_id' => $allocation->stream->siteId,
                'ad_slot_id' => $allocation->stream->adSlotId,
                'revenue_share_rule_id' => $allocation->revenueShare->ruleId,
                'revenue_share_rule_key' => $allocation->revenueShare->ruleKey,
                'share_ratio_bps' => $allocation->revenueShare->shareRatioBps,
                'bid_points_per_thousand' => $allocation->bidPointsPerThousand,
                'assessed_gross_points' => $allocation->assessedGrossPoints,
                'status' => $allocation->status->value,
                'reason' => $allocation->reason,
                'gross_remainder_before' => $allocation->grossRemainderBefore,
                'gross_points' => $allocation->grossPoints,
                'gross_remainder_after' => $allocation->grossRemainderAfter,
                'publisher_share_remainder_before' => $allocation->publisherShareRemainderBefore,
                'publisher_points' => $allocation->publisherPoints,
                'publisher_share_remainder_after' => $allocation->publisherShareRemainderAfter,
                'platform_points' => $allocation->platformPoints,
                'advertiser_ledger_entry_id' => $allocation->advertiserLedgerEntryId,
                'publisher_ledger_entry_id' => $allocation->publisherLedgerEntryId,
                'reservation_id' => $allocation->reservationId,
                'occurred_at' => $this->formatDate($allocation->occurredAt),
                'processed_at' => $allocation->processedAt === null ? null : $this->formatDate($allocation->processedAt),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findAllocationForUpdate($allocation->eventKey);
            if ($existing !== null) {
                return new CpmBillingClaim($existing, acquired: false);
            }

            throw $exception;
        }

        $claimed = $this->findAllocationForUpdate($allocation->eventKey)
            ?? $allocation->withId((int) $this->connection->lastInsertId());

        return new CpmBillingClaim($claimed, acquired: true);
    }

    public function completeAllocation(CpmBillingAllocation $allocation): CpmBillingAllocation
    {
        if ($allocation->status === CpmBillingAllocationStatus::Processing) {
            throw new RuntimeException('CPM allocation completion must have a final status.');
        }
        if ($allocation->id === null) {
            throw new RuntimeException('CPM allocation claim ID is required for completion.');
        }

        $changed = $this->connection->update(
            'cpm_billing_event_allocations',
            [
                'accumulator_id' => $allocation->accumulatorId,
                'assessed_gross_points' => $allocation->assessedGrossPoints,
                'status' => $allocation->status->value,
                'reason' => $allocation->reason,
                'gross_remainder_before' => $allocation->grossRemainderBefore,
                'gross_points' => $allocation->grossPoints,
                'gross_remainder_after' => $allocation->grossRemainderAfter,
                'publisher_share_remainder_before' => $allocation->publisherShareRemainderBefore,
                'publisher_points' => $allocation->publisherPoints,
                'publisher_share_remainder_after' => $allocation->publisherShareRemainderAfter,
                'platform_points' => $allocation->platformPoints,
                'advertiser_ledger_entry_id' => $allocation->advertiserLedgerEntryId,
                'publisher_ledger_entry_id' => $allocation->publisherLedgerEntryId,
                'reservation_id' => $allocation->reservationId,
                'processed_at' => $allocation->processedAt === null ? null : $this->formatDate($allocation->processedAt),
            ],
            [
                'id' => $allocation->id,
                'event_key' => $allocation->eventKey,
                'status' => CpmBillingAllocationStatus::Processing->value,
            ],
        );
        if ($changed !== 1) {
            throw new RuntimeException('CPM allocation claim is not available for completion.');
        }

        return $this->findAllocationForUpdate($allocation->eventKey)
            ?? throw new RuntimeException('CPM allocation could not be loaded after completion.');
    }

    /** @return list<string> */
    private function accumulatorColumns(): array
    {
        return [
            'id',
            'stream_key',
            'advertiser_organization_id',
            'campaign_id',
            'publisher_organization_id',
            'site_id',
            'ad_slot_id',
            'gross_remainder_milli_points',
            'publisher_share_remainder_numerator',
            'impression_count',
            'billed_points',
            'publisher_points',
            'version',
            'updated_at',
        ];
    }

    /** @return list<string> */
    private function allocationColumns(): array
    {
        return [
            'id',
            'event_key',
            'event_type',
            'event_id',
            'decision_id',
            'stream_key',
            'accumulator_id',
            'advertiser_organization_id',
            'campaign_id',
            'publisher_organization_id',
            'site_id',
            'ad_slot_id',
            'revenue_share_rule_id',
            'revenue_share_rule_key',
            'share_ratio_bps',
            'bid_points_per_thousand',
            'assessed_gross_points',
            'status',
            'reason',
            'gross_remainder_before',
            'gross_points',
            'gross_remainder_after',
            'publisher_share_remainder_before',
            'publisher_points',
            'publisher_share_remainder_after',
            'platform_points',
            'advertiser_ledger_entry_id',
            'publisher_ledger_entry_id',
            'reservation_id',
            'occurred_at',
            'processed_at',
        ];
    }

    private function findAllocationForUpdate(string $eventKey): ?CpmBillingAllocation
    {
        $sql = 'SELECT ' . implode(', ', $this->allocationColumns())
            . ' FROM cpm_billing_event_allocations WHERE event_key = ?';
        if ($this->supportsForUpdate()) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, [trim($eventKey)]);

        return $row === false ? null : $this->hydrateAllocation($row);
    }

    private function ensureAccumulator(CpmBillingStream $stream): void
    {
        $values = [
            'stream_key' => $stream->key(),
            'advertiser_organization_id' => $stream->advertiserOrganizationId,
            'campaign_id' => $stream->campaignId,
            'publisher_organization_id' => $stream->publisherOrganizationId,
            'site_id' => $stream->siteId,
            'ad_slot_id' => $stream->adSlotId,
            'gross_remainder_milli_points' => 0,
            'publisher_share_remainder_numerator' => 0,
            'impression_count' => 0,
            'billed_points' => 0,
            'publisher_points' => 0,
            'version' => 0,
            'updated_at' => $this->nowSql(),
        ];

        if ($this->isSqlite()) {
            $this->connection->executeStatement(
                'INSERT OR IGNORE INTO cpm_billing_accumulators '
                    . '(stream_key, advertiser_organization_id, campaign_id, publisher_organization_id, site_id, ad_slot_id, '
                    . 'gross_remainder_milli_points, publisher_share_remainder_numerator, impression_count, billed_points, '
                    . 'publisher_points, version, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_values($values),
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO cpm_billing_accumulators '
                . '(stream_key, advertiser_organization_id, campaign_id, publisher_organization_id, site_id, ad_slot_id, '
                . 'gross_remainder_milli_points, publisher_share_remainder_numerator, impression_count, billed_points, '
                . 'publisher_points, version, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE updated_at = updated_at',
            array_values($values),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateAccumulator(array $row): CpmBillingAccumulator
    {
        $stream = new CpmBillingStream(
            advertiserOrganizationId: (int) $row['advertiser_organization_id'],
            campaignId: (int) $row['campaign_id'],
            publisherOrganizationId: (int) $row['publisher_organization_id'],
            siteId: (int) $row['site_id'],
            adSlotId: (int) $row['ad_slot_id'],
        );

        return new CpmBillingAccumulator(
            id: (int) $row['id'],
            stream: $stream,
            grossRemainderMilliPoints: (int) $row['gross_remainder_milli_points'],
            publisherShareRemainderNumerator: (int) $row['publisher_share_remainder_numerator'],
            impressionCount: (int) $row['impression_count'],
            billedPoints: (int) $row['billed_points'],
            publisherPoints: (int) $row['publisher_points'],
            version: (int) $row['version'],
            updatedAt: $row['updated_at'] === null ? null : new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateAllocation(array $row): CpmBillingAllocation
    {
        $stream = new CpmBillingStream(
            advertiserOrganizationId: (int) $row['advertiser_organization_id'],
            campaignId: (int) $row['campaign_id'],
            publisherOrganizationId: (int) $row['publisher_organization_id'],
            siteId: (int) $row['site_id'],
            adSlotId: (int) $row['ad_slot_id'],
        );
        $revenueShare = new CpmRevenueShareSnapshot(
            ruleId: $row['revenue_share_rule_id'] === null ? null : (int) $row['revenue_share_rule_id'],
            ruleKey: (string) $row['revenue_share_rule_key'],
            shareRatioBps: (int) $row['share_ratio_bps'],
        );

        return new CpmBillingAllocation(
            id: (int) $row['id'],
            eventKey: (string) $row['event_key'],
            eventType: (string) $row['event_type'],
            eventId: (string) $row['event_id'],
            decisionId: (string) $row['decision_id'],
            stream: $stream,
            revenueShare: $revenueShare,
            accumulatorId: $row['accumulator_id'] === null ? null : (int) $row['accumulator_id'],
            bidPointsPerThousand: (int) $row['bid_points_per_thousand'],
            assessedGrossPoints: (int) $row['assessed_gross_points'],
            status: CpmBillingAllocationStatus::from((string) $row['status']),
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            grossRemainderBefore: (int) $row['gross_remainder_before'],
            grossPoints: (int) $row['gross_points'],
            grossRemainderAfter: (int) $row['gross_remainder_after'],
            publisherShareRemainderBefore: (int) $row['publisher_share_remainder_before'],
            publisherPoints: (int) $row['publisher_points'],
            publisherShareRemainderAfter: (int) $row['publisher_share_remainder_after'],
            platformPoints: (int) $row['platform_points'],
            advertiserLedgerEntryId: $row['advertiser_ledger_entry_id'] === null ? null : (int) $row['advertiser_ledger_entry_id'],
            publisherLedgerEntryId: $row['publisher_ledger_entry_id'] === null ? null : (int) $row['publisher_ledger_entry_id'],
            reservationId: $row['reservation_id'] === null ? null : (string) $row['reservation_id'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
            processedAt: $row['processed_at'] === null ? null : new DateTimeImmutable((string) $row['processed_at'], new DateTimeZone('UTC')),
        );
    }

    private function supportsForUpdate(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function nowSql(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function formatDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
