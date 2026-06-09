<?php

declare(strict_types=1);

namespace VertoAD\Repository\Fraud;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Fraud\FraudRiskFeature;

final readonly class DatabaseFraudRiskFeatureRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{viewer_rows: int, slot_rows: int, high_risk_rows: int}
     */
    public function refreshFromEvents(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('Fraud feature refresh end time must be after start time.');
        }

        $events = $this->connection->createQueryBuilder()
            ->select('event_type', 'site_id', 'slot_id', 'viewer_id', 'valid', 'reason')
            ->from('ad_serving_events')
            ->where('event_type IN (:impression, :click)')
            ->andWhere('occurred_at >= :from_time')
            ->andWhere('occurred_at < :to_time')
            ->setParameter('impression', 'impression')
            ->setParameter('click', 'click')
            ->setParameter('from_time', $this->formatDate($from))
            ->setParameter('to_time', $this->formatDate($to))
            ->fetchAllAssociative();

        $viewerBuckets = [];
        $slotBuckets = [];
        foreach ($events as $event) {
            $viewerId = (string) $event['viewer_id'];
            $slotId = (int) $event['slot_id'];
            $viewerBuckets[$viewerId] ??= $this->emptyBucket(
                scopeType: 'viewer',
                scopeId: $viewerId,
                siteId: (int) $event['site_id'],
                slotId: $slotId,
                viewerId: $viewerId,
            );
            $slotBuckets[(string) $slotId] ??= $this->emptyBucket(
                scopeType: 'slot',
                scopeId: (string) $slotId,
                siteId: (int) $event['site_id'],
                slotId: $slotId,
                viewerId: null,
            );
            $this->applyEvent($viewerBuckets[$viewerId], $event);
            $this->applyEvent($slotBuckets[(string) $slotId], $event);
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->createQueryBuilder()
                ->delete('fraud_risk_features')
                ->where('window_start = :window_start')
                ->andWhere('window_end = :window_end')
                ->setParameter('window_start', $this->formatDate($from))
                ->setParameter('window_end', $this->formatDate($to))
                ->executeStatement();

            $highRiskRows = 0;
            foreach ([...array_values($viewerBuckets), ...array_values($slotBuckets)] as $bucket) {
                $feature = $this->scoreBucket($bucket, $from, $to);
                if ($feature->riskBucket === 'high') {
                    ++$highRiskRows;
                }
                $this->insertFeature($feature);
            }

            $this->connection->commit();

            return [
                'viewer_rows' => count($viewerBuckets),
                'slot_rows' => count($slotBuckets),
                'high_risk_rows' => $highRiskRows,
            ];
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function latestForScope(string $scopeType, string $scopeId): ?FraudRiskFeature
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'scope_type',
                'scope_id',
                'window_start',
                'window_end',
                'site_id',
                'slot_id',
                'viewer_id',
                'impressions',
                'clicks',
                'invalid_clicks',
                'ctr_per_mille',
                'invalid_click_rate_per_mille',
                'risk_score',
                'risk_bucket',
                'reasons_json',
                'computed_at',
            )
            ->from('fraud_risk_features')
            ->where('scope_type = :scope_type')
            ->andWhere('scope_id = :scope_id')
            ->setParameter('scope_type', $scopeType)
            ->setParameter('scope_id', $scopeId)
            ->orderBy('window_end', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->featureFromRow($row);
    }

    /**
     * @return array{
     *     scope_type: string,
     *     scope_id: string,
     *     site_id: int|null,
     *     slot_id: int|null,
     *     viewer_id: string|null,
     *     impressions: int,
     *     clicks: int,
     *     invalid_clicks: int
     * }
     */
    private function emptyBucket(
        string $scopeType,
        string $scopeId,
        ?int $siteId,
        ?int $slotId,
        ?string $viewerId,
    ): array {
        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'site_id' => $siteId,
            'slot_id' => $slotId,
            'viewer_id' => $viewerId,
            'impressions' => 0,
            'clicks' => 0,
            'invalid_clicks' => 0,
        ];
    }

    /**
     * @param array<string, int|string|null> $bucket
     * @param array<string, mixed> $event
     */
    private function applyEvent(array &$bucket, array $event): void
    {
        if ($event['event_type'] === 'impression') {
            ++$bucket['impressions'];
            return;
        }

        ++$bucket['clicks'];
        if ((int) $event['valid'] !== 1 || $event['reason'] !== null) {
            ++$bucket['invalid_clicks'];
        }
    }

    /**
     * @param array<string, int|string|null> $bucket
     */
    private function scoreBucket(array $bucket, \DateTimeImmutable $from, \DateTimeImmutable $to): FraudRiskFeature
    {
        $impressions = (int) $bucket['impressions'];
        $clicks = (int) $bucket['clicks'];
        $invalidClicks = (int) $bucket['invalid_clicks'];
        $ctrPerMille = $impressions > 0 ? (int) round(($clicks / $impressions) * 1000) : ($clicks > 0 ? 10000 : 0);
        $invalidRatePerMille = $clicks > 0 ? (int) round(($invalidClicks / $clicks) * 1000) : 0;
        $reasons = [];
        $riskScore = 15;

        if ($clicks > 0 && $impressions === 0) {
            $riskScore = 95;
            $reasons[] = 'click_without_impression';
        }
        if ($invalidRatePerMille >= 500) {
            $riskScore = max($riskScore, 90);
            $reasons[] = 'invalid_click_rate';
        } elseif ($invalidClicks > 0) {
            $riskScore = max($riskScore, 70);
            $reasons[] = 'invalid_clicks';
        }
        if ($ctrPerMille >= 1500) {
            $riskScore = max($riskScore, 75);
            $reasons[] = 'excessive_ctr';
        } elseif ($ctrPerMille >= 1000) {
            $riskScore = max($riskScore, 60);
            $reasons[] = 'elevated_ctr';
        }

        return new FraudRiskFeature(
            scopeType: (string) $bucket['scope_type'],
            scopeId: (string) $bucket['scope_id'],
            windowStart: $from,
            windowEnd: $to,
            siteId: $bucket['site_id'] === null ? null : (int) $bucket['site_id'],
            slotId: $bucket['slot_id'] === null ? null : (int) $bucket['slot_id'],
            viewerId: $bucket['viewer_id'] === null ? null : (string) $bucket['viewer_id'],
            impressions: $impressions,
            clicks: $clicks,
            invalidClicks: $invalidClicks,
            ctrPerMille: $ctrPerMille,
            invalidClickRatePerMille: $invalidRatePerMille,
            riskScore: $riskScore,
            riskBucket: $this->riskBucket($riskScore),
            reasons: array_values(array_unique($reasons)),
            computedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function riskBucket(int $riskScore): string
    {
        return match (true) {
            $riskScore >= 80 => 'high',
            $riskScore >= 60 => 'review',
            $riskScore >= 40 => 'medium',
            default => 'low',
        };
    }

    private function insertFeature(FraudRiskFeature $feature): void
    {
        $this->connection->insert('fraud_risk_features', [
            'scope_type' => $feature->scopeType,
            'scope_id' => $feature->scopeId,
            'window_start' => $this->formatDate($feature->windowStart),
            'window_end' => $this->formatDate($feature->windowEnd),
            'site_id' => $feature->siteId,
            'slot_id' => $feature->slotId,
            'viewer_id' => $feature->viewerId,
            'impressions' => $feature->impressions,
            'clicks' => $feature->clicks,
            'invalid_clicks' => $feature->invalidClicks,
            'ctr_per_mille' => $feature->ctrPerMille,
            'invalid_click_rate_per_mille' => $feature->invalidClickRatePerMille,
            'risk_score' => $feature->riskScore,
            'risk_bucket' => $feature->riskBucket,
            'reasons_json' => json_encode($feature->reasons, JSON_THROW_ON_ERROR),
            'computed_at' => $this->formatDate($feature->computedAt),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function featureFromRow(array $row): FraudRiskFeature
    {
        $reasons = json_decode((string) $row['reasons_json'], true, flags: JSON_THROW_ON_ERROR);

        return new FraudRiskFeature(
            scopeType: (string) $row['scope_type'],
            scopeId: (string) $row['scope_id'],
            windowStart: new \DateTimeImmutable((string) $row['window_start'], new \DateTimeZone('UTC')),
            windowEnd: new \DateTimeImmutable((string) $row['window_end'], new \DateTimeZone('UTC')),
            siteId: $row['site_id'] === null ? null : (int) $row['site_id'],
            slotId: $row['slot_id'] === null ? null : (int) $row['slot_id'],
            viewerId: $row['viewer_id'] === null ? null : (string) $row['viewer_id'],
            impressions: (int) $row['impressions'],
            clicks: (int) $row['clicks'],
            invalidClicks: (int) $row['invalid_clicks'],
            ctrPerMille: (int) $row['ctr_per_mille'],
            invalidClickRatePerMille: (int) $row['invalid_click_rate_per_mille'],
            riskScore: (int) $row['risk_score'],
            riskBucket: (string) $row['risk_bucket'],
            reasons: is_array($reasons) ? array_values(array_map('strval', $reasons)) : [],
            computedAt: new \DateTimeImmutable((string) $row['computed_at'], new \DateTimeZone('UTC')),
        );
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
