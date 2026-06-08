<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Serving\AdDecision;

final readonly class DatabaseAdDecisionRepository implements AdDecisionRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(AdDecision $decision): void
    {
        $values = [
            'decision_id' => $decision->decisionId,
            'site_id' => $decision->siteId,
            'slot_id' => $decision->slotId,
            'viewer_id' => $decision->viewerId,
            'filled' => $decision->filled ? 1 : 0,
            'reason' => $decision->reason,
            'iframe_html' => $decision->iframeHtml,
            'width' => $decision->width,
            'height' => $decision->height,
            'ad_id' => $decision->adId,
            'campaign_id' => $decision->campaignId,
            'advertiser_organization_id' => $decision->advertiserOrganizationId,
            'publisher_organization_id' => $decision->publisherOrganizationId,
            'impression_cost_points' => $decision->impressionCostPoints,
            'click_cost_points' => $decision->clickCostPoints,
            'landing_url' => $decision->landingUrl,
            'decided_at' => $this->formatDate($decision->decidedAt),
        ];

        if ($this->find($decision->decisionId) === null) {
            $this->connection->insert('ad_serving_decisions', $values);

            return;
        }

        $this->connection->update(
            'ad_serving_decisions',
            array_diff_key($values, ['decision_id' => true]),
            ['decision_id' => $decision->decisionId],
        );
    }

    public function find(string $decisionId): ?AdDecision
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'decision_id',
                'site_id',
                'slot_id',
                'viewer_id',
                'filled',
                'reason',
                'iframe_html',
                'width',
                'height',
                'ad_id',
                'campaign_id',
                'advertiser_organization_id',
                'publisher_organization_id',
                'impression_cost_points',
                'click_cost_points',
                'landing_url',
                'decided_at',
            )
            ->from('ad_serving_decisions')
            ->where('decision_id = :decision_id')
            ->setParameter('decision_id', trim($decisionId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AdDecision
    {
        return new AdDecision(
            decisionId: (string) $row['decision_id'],
            siteId: (int) $row['site_id'],
            slotId: (int) $row['slot_id'],
            viewerId: (string) $row['viewer_id'],
            filled: (bool) $row['filled'],
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            iframeHtml: (string) $row['iframe_html'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            adId: $row['ad_id'] === null ? null : (string) $row['ad_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            advertiserOrganizationId: $row['advertiser_organization_id'] === null ? null : (int) $row['advertiser_organization_id'],
            publisherOrganizationId: $row['publisher_organization_id'] === null ? null : (int) $row['publisher_organization_id'],
            impressionCostPoints: $row['impression_cost_points'] === null ? null : (int) $row['impression_cost_points'],
            clickCostPoints: $row['click_cost_points'] === null ? null : (int) $row['click_cost_points'],
            landingUrl: $row['landing_url'] === null ? null : (string) $row['landing_url'],
            decidedAt: new DateTimeImmutable((string) $row['decided_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
