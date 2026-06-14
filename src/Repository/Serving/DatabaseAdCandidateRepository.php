<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Campaign\CampaignTargeting;
use VertoAD\Domain\Serving\AdCandidate;

final readonly class DatabaseAdCandidateRepository implements AdCandidateRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array{width:int,height:int}|null $size
     * @return list<AdCandidate>
     */
    public function eligibleCandidatesForSlot(int $siteId, int $slotId, ?array $size): array
    {
        $now = new DateTimeImmutable();
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'c.id AS campaign_id',
                'c.organization_id AS advertiser_organization_id',
                'c.pricing_model',
                'c.bid_points',
                'c.landing_url',
                'c.targeting_json',
                'a.id AS asset_id',
                'a.type AS asset_type',
                'a.object_key',
                'a.content_type',
                'a.width',
                'a.height',
                'r.ai_risk_score',
                'COALESCE(SUM(ra.impressions), 0) AS historical_impressions',
                'COALESCE(SUM(ra.clicks), 0) AS historical_clicks',
                'fc.hourly_impression_cap',
                'fc.daily_impression_cap',
            )
            ->from('campaigns', 'c')
            ->innerJoin('c', 'creative_assets', 'a', 'a.id = c.creative_asset_id AND a.organization_id = c.organization_id')
            ->innerJoin('c', 'creative_reviews', 'r', 'r.asset_id = c.creative_asset_id AND r.organization_id = c.organization_id')
            ->leftJoin('c', 'report_aggregates', 'ra', "ra.campaign_id = c.id AND ra.organization_id = c.organization_id AND ra.organization_role = 'advertiser' AND ra.granularity = 'day'")
            ->leftJoin('c', 'campaign_serving_frequency_caps', 'fc', 'fc.campaign_id = c.id AND fc.organization_id = c.organization_id')
            ->where('c.status = :campaign_status')
            ->andWhere("a.status IN ('pending_review', 'confirmed')")
            ->andWhere('r.status = :review_status')
            ->andWhere('r.final_decision = :final_decision')
            ->andWhere('c.landing_url <> :empty_landing_url')
            ->andWhere('(c.starts_at IS NULL OR c.starts_at <= :now)')
            ->andWhere('(c.ends_at IS NULL OR c.ends_at >= :now)')
            ->setParameter('campaign_status', 'active')
            ->setParameter('review_status', 'approved')
            ->setParameter('final_decision', 'approved')
            ->setParameter('empty_landing_url', '')
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->groupBy('c.id')
            ->addGroupBy('c.organization_id')
            ->addGroupBy('c.pricing_model')
            ->addGroupBy('c.bid_points')
            ->addGroupBy('c.landing_url')
            ->addGroupBy('c.targeting_json')
            ->addGroupBy('a.id')
            ->addGroupBy('a.type')
            ->addGroupBy('a.object_key')
            ->addGroupBy('a.content_type')
            ->addGroupBy('a.width')
            ->addGroupBy('a.height')
            ->addGroupBy('r.ai_risk_score')
            ->addGroupBy('r.final_decided_at')
            ->addGroupBy('fc.hourly_impression_cap')
            ->addGroupBy('fc.daily_impression_cap')
            ->orderBy('c.bid_points', 'DESC')
            ->addOrderBy('r.final_decided_at', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->fetchAllAssociative();

        $candidates = [];
        foreach ($rows as $row) {
            $width = (int) $row['width'];
            $height = (int) $row['height'];
            if ($size !== null && ($width !== (int) $size['width'] || $height !== (int) $size['height'])) {
                continue;
            }

            $targeting = $this->targeting($row['targeting_json'] === null ? '' : (string) $row['targeting_json']);
            if (!$this->targetsSlot($targeting, $siteId, $slotId)) {
                continue;
            }

            $bidPoints = (int) $row['bid_points'];
            $pricingModel = (string) $row['pricing_model'];
            $candidates[] = new AdCandidate(
                adId: 'asset-' . (int) $row['asset_id'],
                campaignId: (int) $row['campaign_id'],
                advertiserOrganizationId: (int) $row['advertiser_organization_id'],
                creativeHtml: $this->creativeHtml($row),
                landingUrl: (string) $row['landing_url'],
                width: $width,
                height: $height,
                impressionCostPoints: $pricingModel === 'cpm' ? $bidPoints : 0,
                clickCostPoints: $pricingModel === 'cpc' ? $bidPoints : 0,
                assetType: (string) $row['asset_type'],
                assetObjectKey: (string) $row['object_key'],
                assetContentType: (string) $row['content_type'],
                qualityScore: $this->qualityScore($row['ai_risk_score']),
                historicalCtrPerMille: $this->historicalCtrPerMille((int) $row['historical_impressions'], (int) $row['historical_clicks']),
                hourlyFrequencyCap: $row['hourly_impression_cap'] === null ? null : (int) $row['hourly_impression_cap'],
                dailyFrequencyCap: $row['daily_impression_cap'] === null ? null : (int) $row['daily_impression_cap'],
            );
        }

        return $candidates;
    }

    private function targeting(string $json): CampaignTargeting
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? CampaignTargeting::fromArray($decoded) : new CampaignTargeting();
    }

    private function targetsSlot(CampaignTargeting $targeting, int $siteId, int $slotId): bool
    {
        if ($targeting->siteIds !== [] && !in_array($siteId, $targeting->siteIds, true)) {
            return false;
        }

        return $targeting->slotIds === [] || in_array($slotId, $targeting->slotIds, true);
    }

    /** @param array<string, mixed> $row */
    private function creativeHtml(array $row): string
    {
        $objectKey = htmlspecialchars((string) $row['object_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = htmlspecialchars((string) $row['asset_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div data-vertoad-asset="' . $objectKey . '" data-vertoad-asset-type="' . $type . '"></div>';
    }

    private function qualityScore(mixed $aiRiskScore): int
    {
        if ($aiRiskScore === null) {
            return 100;
        }

        return max(0, min(100, 100 - (int) round(((float) $aiRiskScore) * 100)));
    }

    private function historicalCtrPerMille(int $impressions, int $clicks): int
    {
        if ($impressions <= 0 || $clicks <= 0) {
            return 0;
        }

        return max(0, min(10_000, (int) round(($clicks / $impressions) * 1000)));
    }
}
