<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Serving\AdCandidate;

final readonly class DefaultAdSelectionPolicy implements AdSelectionPolicyInterface
{
    private ServingFrequencyCapStoreInterface $frequencyCapStore;
    private ServingRiskAssessorInterface $trafficRiskAssessor;

    public function __construct(
        ?ServingFrequencyCapStoreInterface $frequencyCaps = null,
        ?ServingRiskAssessorInterface $riskAssessor = null,
    ) {
        $this->frequencyCapStore = $frequencyCaps ?? new InMemoryServingFrequencyCapStore();
        $this->trafficRiskAssessor = $riskAssessor ?? new AllowAllServingRiskAssessor();
    }

    public static function inMemory(): self
    {
        return new self(new InMemoryServingFrequencyCapStore(), new AllowAllServingRiskAssessor());
    }

    public function trafficRisk(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision
    {
        return $this->riskAssessor()->assess($siteId, $slotId, $viewerId);
    }

    public function rankCandidates(
        array $candidates,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): array {
        usort(
            $candidates,
            fn (AdCandidate $a, AdCandidate $b): int => $this->score($b) <=> $this->score($a)
                ?: $b->qualityScore <=> $a->qualityScore
                ?: $b->historicalCtrPerMille <=> $a->historicalCtrPerMille
                ?: max($b->impressionCostPoints, $b->clickCostPoints) <=> max($a->impressionCostPoints, $a->clickCostPoints)
                ?: $a->campaignId <=> $b->campaignId,
        );

        return array_values($candidates);
    }

    public function canServeCandidate(
        AdCandidate $candidate,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): bool {
        $frequencyCaps = $this->frequencyCaps();
        if (
            $candidate->hourlyFrequencyCap !== null
            && $frequencyCaps->servedCount($candidate->campaignId, $slotId, $viewerId, 'hour', $now) >= $candidate->hourlyFrequencyCap
        ) {
            return false;
        }

        return $candidate->dailyFrequencyCap === null
            || $frequencyCaps->servedCount($candidate->campaignId, $slotId, $viewerId, 'day', $now) < $candidate->dailyFrequencyCap;
    }

    public function recordServe(
        AdCandidate $candidate,
        int $siteId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $now,
    ): void {
        if ($candidate->hourlyFrequencyCap === null && $candidate->dailyFrequencyCap === null) {
            return;
        }

        $this->frequencyCaps()->recordServe($candidate->campaignId, $slotId, $viewerId, $now);
    }

    private function score(AdCandidate $candidate): int
    {
        $bid = max($candidate->impressionCostPoints, $candidate->clickCostPoints);

        return ($bid * max(0, $candidate->qualityScore)) + min(max(0, $candidate->historicalCtrPerMille), 10_000);
    }

    private function frequencyCaps(): ServingFrequencyCapStoreInterface
    {
        return $this->frequencyCapStore;
    }

    private function riskAssessor(): ServingRiskAssessorInterface
    {
        return $this->trafficRiskAssessor;
    }
}
