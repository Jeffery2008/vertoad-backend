<?php

declare(strict_types=1);

namespace VertoAD\Repository\Attribution;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VertoAD\Domain\Attribution\ConversionAttributionResult;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;

final readonly class DatabaseAttributionEventRepository implements AttributionEventRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordClick(AdDecision $decision, string $clickEventId, DateTimeImmutable $occurredAt): void
    {
        (new DatabaseAdDecisionRepository($this->connection))->save($decision);
        (new DatabaseAdEventRepository($this->connection))->recordClick($decision, $clickEventId, $occurredAt);
    }

    public function findLastClick(string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): ?array
    {
        $windowStart = $occurredAt->modify('-' . $windowSeconds . ' seconds');
        $row = $this->connection->createQueryBuilder()
            ->select(
                'e.event_id AS click_event_id',
                'e.occurred_at',
                'd.decision_id',
                'd.site_id',
                'd.slot_id',
                'd.viewer_id',
                'd.filled',
                'd.reason',
                'd.iframe_html',
                'd.width',
                'd.height',
                'd.ad_id',
                'd.campaign_id',
                'd.advertiser_organization_id',
                'd.publisher_organization_id',
                'd.impression_cost_points',
                'd.click_cost_points',
                'd.landing_url',
                'd.decided_at',
            )
            ->from('ad_serving_events', 'e')
            ->innerJoin('e', 'ad_serving_decisions', 'd', 'd.decision_id = e.decision_id')
            ->where('e.event_type = :event_type')
            ->andWhere('e.valid = :valid')
            ->andWhere('e.viewer_id = :viewer_id')
            ->andWhere('e.occurred_at >= :window_start')
            ->andWhere('e.occurred_at <= :occurred_at')
            ->setParameter('event_type', 'click')
            ->setParameter('valid', 1)
            ->setParameter('viewer_id', trim($viewerId))
            ->setParameter('window_start', $this->formatDate($windowStart))
            ->setParameter('occurred_at', $this->formatDate($occurredAt))
            ->orderBy('e.occurred_at', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'decision' => $this->hydrateDecision($row),
            'click_event_id' => (string) $row['click_event_id'],
            'occurred_at' => new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
        ];
    }

    public function findConversion(string $eventId): ?ConversionAttributionResult
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'conversion_id',
                'organization_id',
                'oauth_client_id',
                'recorded_by_user_id',
                'attributed',
                'click_event_id',
                'decision_id',
                'campaign_id',
                'window_seconds',
                'source',
                'conversion_name',
                'value_points',
                'occurred_at',
            )
            ->from('attribution_conversions')
            ->where('event_id = :event_id')
            ->setParameter('event_id', trim($eventId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateConversion($row, false);
    }

    public function recordConversion(string $eventId, ConversionAttributionResult $result): ConversionAttributionResult
    {
        try {
            $this->connection->insert('attribution_conversions', [
                'event_id' => trim($eventId),
                'conversion_id' => $result->conversionId,
                'organization_id' => $result->organizationId,
                'oauth_client_id' => $result->oauthClientId,
                'recorded_by_user_id' => $result->recordedByUserId,
                'attributed' => $result->attributed ? 1 : 0,
                'click_event_id' => $result->clickEventId,
                'decision_id' => $result->decisionId,
                'campaign_id' => $result->campaignId,
                'window_seconds' => $result->windowSeconds,
                'source' => $result->source,
                'conversion_name' => $result->conversionName,
                'value_points' => $result->valuePoints,
                'occurred_at' => $this->formatDate($result->occurredAt),
                'created_at' => $this->formatDate(new DateTimeImmutable()),
            ]);

            return $result;
        } catch (UniqueConstraintViolationException) {
            return $this->findConversion($eventId) ?? $result;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDecision(array $row): AdDecision
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateConversion(array $row, bool $duplicate): ConversionAttributionResult
    {
        return new ConversionAttributionResult(
            conversionId: (string) $row['conversion_id'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            oauthClientId: $row['oauth_client_id'] === null ? null : (int) $row['oauth_client_id'],
            recordedByUserId: $row['recorded_by_user_id'] === null ? null : (int) $row['recorded_by_user_id'],
            attributed: (bool) $row['attributed'],
            duplicate: $duplicate,
            clickEventId: $row['click_event_id'] === null ? null : (string) $row['click_event_id'],
            decisionId: $row['decision_id'] === null ? null : (string) $row['decision_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            windowSeconds: (int) $row['window_seconds'],
            source: (string) $row['source'],
            conversionName: (string) $row['conversion_name'],
            valuePoints: (int) $row['value_points'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
