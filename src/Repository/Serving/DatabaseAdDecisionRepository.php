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
            'request_id' => $decision->requestId,
            'ip_address' => $decision->ipAddress,
            'user_agent' => $decision->userAgent,
            'geo_code' => $decision->geoCode,
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
                'request_id',
                'ip_address',
                'user_agent',
                'geo_code',
            )
            ->from('ad_serving_decisions')
            ->where('decision_id = :decision_id')
            ->setParameter('decision_id', trim($decisionId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function searchDecisions(array $filters): array
    {
        $query = $this->connection->createQueryBuilder()
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
                'request_id',
                'ip_address',
                'user_agent',
                'geo_code',
            )
            ->from('ad_serving_decisions')
            ->orderBy('decided_at', 'DESC');

        if (isset($filters['request_id']) && trim((string) $filters['request_id']) !== '') {
            $query->andWhere('request_id = :request_id')
                ->setParameter('request_id', trim((string) $filters['request_id']));
        }
        if (isset($filters['ip_address']) && trim((string) $filters['ip_address']) !== '') {
            $query->andWhere('ip_address = :ip_address')
                ->setParameter('ip_address', trim((string) $filters['ip_address']));
        }
        if (isset($filters['occurred_from']) && trim((string) $filters['occurred_from']) !== '') {
            $query->andWhere('decided_at >= :occurred_from')
                ->setParameter('occurred_from', $this->formatDate(new DateTimeImmutable((string) $filters['occurred_from'])));
        }
        if (isset($filters['occurred_to']) && trim((string) $filters['occurred_to']) !== '') {
            $query->andWhere('decided_at <= :occurred_to')
                ->setParameter('occurred_to', $this->formatDate(new DateTimeImmutable((string) $filters['occurred_to'])));
        }
        if (isset($filters['limit']) && is_int($filters['limit']) && $filters['limit'] > 0) {
            $query->setMaxResults($filters['limit']);
        }

        return array_map(fn (array $row): AdDecision => $this->hydrate($row), $query->fetchAllAssociative());
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
            requestId: $row['request_id'] === null ? null : (string) $row['request_id'],
            ipAddress: $row['ip_address'] === null ? null : (string) $row['ip_address'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            geoCode: $row['geo_code'] === null ? null : (string) $row['geo_code'],
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
