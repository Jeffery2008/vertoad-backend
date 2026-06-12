<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use VertoAD\Domain\Billing\PublisherEarningEvent;
use VertoAD\Domain\Billing\RevenueShareRule;

final class RevenueShareRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createRule(
        string $scope,
        ?int $organizationId,
        ?int $siteId,
        ?int $adSlotId,
        int $shareRatioBps,
        ?int $createdByUserId,
        DateTimeImmutable $createdAt,
    ): RevenueShareRule {
        $scope = trim($scope);
        if (!in_array($scope, ['global', 'publisher', 'site', 'slot'], true)) {
            throw new InvalidArgumentException('Revenue share scope is invalid.');
        }
        if ($shareRatioBps < 0 || $shareRatioBps > 10000) {
            throw new InvalidArgumentException('Revenue share ratio must be between 0 and 10000 basis points.');
        }
        if (!$this->scopeTargetIsValid($scope, $organizationId, $siteId, $adSlotId)) {
            throw new InvalidArgumentException('Revenue share scope target is invalid.');
        }

        $version = $this->nextVersion();
        $this->connection->insert('revenue_share_rules', [
            'scope' => $scope,
            'organization_id' => $organizationId,
            'site_id' => $siteId,
            'ad_slot_id' => $adSlotId,
            'share_ratio_bps' => $shareRatioBps,
            'status' => 'active',
            'version' => $version,
            'created_by_user_id' => $createdByUserId,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);

        return new RevenueShareRule(
            id: (int) $this->connection->lastInsertId(),
            scope: $scope,
            organizationId: $organizationId,
            siteId: $siteId,
            adSlotId: $adSlotId,
            shareRatioBps: $shareRatioBps,
            status: 'active',
            version: $version,
            createdByUserId: $createdByUserId,
            createdAt: $createdAt,
        );
    }

    public function findBestRule(int $organizationId, int $siteId, int $adSlotId): ?RevenueShareRule
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('revenue_share_rules')
            ->where('status = :status')
            ->andWhere(
                "(scope = 'global')"
                . " OR (scope = 'publisher' AND organization_id = :organization_id)"
                . " OR (scope = 'site' AND site_id = :site_id)"
                . " OR (scope = 'slot' AND ad_slot_id = :ad_slot_id)",
            )
            ->setParameter('status', 'active')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('site_id', $siteId)
            ->setParameter('ad_slot_id', $adSlotId)
            ->fetchAllAssociative();

        $ranked = array_map(fn (array $row): RevenueShareRule => $this->hydrateRule($row), $rows);
        usort($ranked, static function (RevenueShareRule $left, RevenueShareRule $right): int {
            $specificity = ['global' => 0, 'publisher' => 1, 'site' => 2, 'slot' => 3];
            return [$specificity[$right->scope], $right->version, $right->id ?? 0]
                <=> [$specificity[$left->scope], $left->version, $left->id ?? 0];
        });

        return $ranked[0] ?? null;
    }

    public function findActiveGlobalRule(): ?RevenueShareRule
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('revenue_share_rules')
            ->where('scope = :scope')
            ->andWhere('status = :status')
            ->setParameter('scope', 'global')
            ->setParameter('status', 'active')
            ->orderBy('version', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateRule($row);
    }

    public function findEarningByEventId(string $eventId): ?PublisherEarningEvent
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('publisher_earning_events')
            ->where('event_id = :event_id')
            ->setParameter('event_id', $eventId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateEarning($row);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function createEarning(
        string $eventId,
        int $publisherOrganizationId,
        int $siteId,
        int $adSlotId,
        int $advertiserOrganizationId,
        ?int $campaignId,
        int $grossPoints,
        int $shareRatioBps,
        int $publisherPoints,
        int $platformPoints,
        ?int $revenueShareRuleId,
        int $ledgerEntryId,
        ?array $metadata,
        DateTimeImmutable $earnedAt,
    ): PublisherEarningEvent {
        $this->connection->insert('publisher_earning_events', [
            'event_id' => $eventId,
            'publisher_organization_id' => $publisherOrganizationId,
            'site_id' => $siteId,
            'ad_slot_id' => $adSlotId,
            'advertiser_organization_id' => $advertiserOrganizationId,
            'campaign_id' => $campaignId,
            'gross_points' => $grossPoints,
            'share_ratio_bps' => $shareRatioBps,
            'publisher_points' => $publisherPoints,
            'platform_points' => $platformPoints,
            'revenue_share_rule_id' => $revenueShareRuleId,
            'ledger_entry_id' => $ledgerEntryId,
            'metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'earned_at' => $earnedAt->format('Y-m-d H:i:s'),
        ]);

        return new PublisherEarningEvent(
            id: (int) $this->connection->lastInsertId(),
            eventId: $eventId,
            publisherOrganizationId: $publisherOrganizationId,
            siteId: $siteId,
            adSlotId: $adSlotId,
            advertiserOrganizationId: $advertiserOrganizationId,
            campaignId: $campaignId,
            grossPoints: $grossPoints,
            shareRatioBps: $shareRatioBps,
            publisherPoints: $publisherPoints,
            platformPoints: $platformPoints,
            revenueShareRuleId: $revenueShareRuleId,
            ledgerEntryId: $ledgerEntryId,
            metadata: $metadata,
            earnedAt: $earnedAt,
        );
    }

    private function nextVersion(): int
    {
        return ((int) $this->connection->createQueryBuilder()
            ->select('COALESCE(MAX(version), 0)')
            ->from('revenue_share_rules')
            ->fetchOne()) + 1;
    }

    private function scopeTargetIsValid(string $scope, ?int $organizationId, ?int $siteId, ?int $adSlotId): bool
    {
        return match ($scope) {
            'global' => $organizationId === null && $siteId === null && $adSlotId === null,
            'publisher' => $organizationId !== null && $siteId === null && $adSlotId === null,
            'site' => $organizationId === null && $siteId !== null && $adSlotId === null,
            'slot' => $organizationId === null && $siteId === null && $adSlotId !== null,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRule(array $row): RevenueShareRule
    {
        return new RevenueShareRule(
            id: (int) $row['id'],
            scope: (string) $row['scope'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            siteId: $row['site_id'] === null ? null : (int) $row['site_id'],
            adSlotId: $row['ad_slot_id'] === null ? null : (int) $row['ad_slot_id'],
            shareRatioBps: (int) $row['share_ratio_bps'],
            status: (string) $row['status'],
            version: (int) $row['version'],
            createdByUserId: $row['created_by_user_id'] === null ? null : (int) $row['created_by_user_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEarning(array $row): PublisherEarningEvent
    {
        $metadata = $row['metadata_json'] === null ? null : json_decode((string) $row['metadata_json'], true, flags: JSON_THROW_ON_ERROR);
        return new PublisherEarningEvent(
            id: (int) $row['id'],
            eventId: (string) $row['event_id'],
            publisherOrganizationId: (int) $row['publisher_organization_id'],
            siteId: (int) $row['site_id'],
            adSlotId: (int) $row['ad_slot_id'],
            advertiserOrganizationId: (int) $row['advertiser_organization_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            grossPoints: (int) $row['gross_points'],
            shareRatioBps: (int) $row['share_ratio_bps'],
            publisherPoints: (int) $row['publisher_points'],
            platformPoints: (int) $row['platform_points'],
            revenueShareRuleId: $row['revenue_share_rule_id'] === null ? null : (int) $row['revenue_share_rule_id'],
            ledgerEntryId: (int) $row['ledger_entry_id'],
            metadata: is_array($metadata) ? $metadata : null,
            earnedAt: new DateTimeImmutable((string) $row['earned_at']),
        );
    }
}
