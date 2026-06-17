<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Serving\AdCandidate;

interface AdSelectionPolicyInterface
{
    public function trafficRisk(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision;

    /**
     * @param list<AdCandidate> $candidates
     * @return list<AdCandidate>
     */
    public function rankCandidates(
        array $candidates,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): array;

    public function canServeCandidate(
        AdCandidate $candidate,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): bool;

    public function recordServe(
        AdCandidate $candidate,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): void;

    public function recordClick(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): void;
}
