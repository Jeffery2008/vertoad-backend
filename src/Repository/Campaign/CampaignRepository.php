<?php

declare(strict_types=1);

namespace VertoAD\Repository\Campaign;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Campaign\Campaign;
use VertoAD\Domain\Campaign\CampaignStatus;
use VertoAD\Domain\Campaign\CampaignTargeting;
use VertoAD\Domain\Campaign\PricingModel;

final class CampaignRepository implements CampaignRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<Campaign> */
    public function listForOrganization(int $organizationId): array
    {
        $rows = $this->baseQuery()
            ->where('c.organization_id = :organization_id')
            ->setParameter('organization_id', $organizationId)
            ->orderBy('c.id', 'DESC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): Campaign => $this->hydrate($row), $rows);
    }

    public function find(int $organizationId, int $campaignId): ?Campaign
    {
        $row = $this->baseQuery()
            ->where('c.organization_id = :organization_id')
            ->andWhere('c.id = :campaign_id')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('campaign_id', $campaignId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Campaign $campaign): Campaign
    {
        $this->connection->insert('campaigns', $this->rowValues($campaign));

        return $this->find($campaign->organizationId, (int) $this->connection->lastInsertId()) ?? $campaign;
    }

    public function update(Campaign $campaign): Campaign
    {
        $this->connection->update(
            'campaigns',
            $this->rowValues($campaign),
            ['id' => $campaign->id, 'organization_id' => $campaign->organizationId],
        );

        return $this->find($campaign->organizationId, (int) $campaign->id) ?? $campaign;
    }

    public function pauseIfActive(int $organizationId, int $campaignId, string $reason): bool
    {
        if ($organizationId <= 0 || $campaignId <= 0 || trim($reason) === '') {
            return false;
        }

        return $this->connection->update(
            'campaigns',
            [
                'status' => CampaignStatus::Paused->value,
                'pause_reason' => trim($reason),
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            [
                'id' => $campaignId,
                'organization_id' => $organizationId,
                'status' => CampaignStatus::Active->value,
            ],
        ) === 1;
    }

    private function baseQuery(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'c.id',
                'c.organization_id',
                'c.name',
                'c.status',
                'c.pause_reason',
                'c.pricing_model',
                'c.bid_points',
                'c.landing_url',
                'c.creative_asset_id',
                'c.starts_at',
                'c.ends_at',
                'c.targeting_json',
                'b.total_cap_points',
                'b.daily_cap_points',
                'b.hourly_cap_points',
            )
            ->from('campaigns', 'c')
            ->leftJoin('c', 'campaign_budget_caps', 'b', 'b.campaign_id = c.id AND b.organization_id = c.organization_id');
    }

    /** @return array<string, mixed> */
    private function rowValues(Campaign $campaign): array
    {
        return [
            'organization_id' => $campaign->organizationId,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'pricing_model' => $campaign->pricingModel->value,
            'bid_points' => $campaign->bidPoints,
            'landing_url' => $campaign->landingUrl,
            'creative_asset_id' => $campaign->creativeAssetId,
            'starts_at' => $campaign->startsAt?->format('Y-m-d H:i:s'),
            'ends_at' => $campaign->endsAt?->format('Y-m-d H:i:s'),
            'targeting_json' => json_encode($campaign->targeting->toArray(), JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Campaign
    {
        $id = (int) $row['id'];
        $organizationId = (int) $row['organization_id'];
        $targeting = CampaignTargeting::fromJson((string) $row['targeting_json']);

        return new Campaign(
            id: $id,
            organizationId: $organizationId,
            name: (string) $row['name'],
            status: CampaignStatus::from((string) $row['status']),
            pricingModel: PricingModel::from((string) $row['pricing_model']),
            bidPoints: (int) $row['bid_points'],
            landingUrl: (string) $row['landing_url'],
            creativeAssetId: (int) $row['creative_asset_id'],
            startsAt: $row['starts_at'] === null ? null : new DateTimeImmutable((string) $row['starts_at']),
            endsAt: $row['ends_at'] === null ? null : new DateTimeImmutable((string) $row['ends_at']),
            targeting: $targeting,
            budget: $this->hydrateBudget($id, $organizationId, $row),
            pauseReason: $row['pause_reason'] === null ? null : (string) $row['pause_reason'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateBudget(int $campaignId, int $organizationId, array $row): ?CampaignBudgetCaps
    {
        if (
            $row['total_cap_points'] === null
            && $row['daily_cap_points'] === null
            && $row['hourly_cap_points'] === null
        ) {
            return null;
        }

        return new CampaignBudgetCaps(
            campaignId: $campaignId,
            organizationId: $organizationId,
            totalCapPoints: $row['total_cap_points'] === null ? null : (int) $row['total_cap_points'],
            dailyCapPoints: $row['daily_cap_points'] === null ? null : (int) $row['daily_cap_points'],
            hourlyCapPoints: $row['hourly_cap_points'] === null ? null : (int) $row['hourly_cap_points'],
        );
    }
}
