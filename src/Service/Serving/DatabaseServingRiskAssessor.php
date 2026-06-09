<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use VertoAD\Domain\Fraud\FraudRiskFeature;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;

final readonly class DatabaseServingRiskAssessor implements ServingRiskAssessorInterface
{
    public function __construct(private DatabaseFraudRiskFeatureRepository $features)
    {
    }

    public function assess(int $siteId, int $slotId, string $viewerId): AdTrafficRiskDecision
    {
        $viewer = $this->features->latestForScope('viewer', $viewerId);
        if ($this->isHighRisk($viewer)) {
            return AdTrafficRiskDecision::reject('fraud_high_risk_viewer');
        }

        $slot = $this->features->latestForScope('slot', (string) $slotId);
        if ($this->isHighRisk($slot)) {
            return AdTrafficRiskDecision::reject('fraud_high_risk_slot');
        }

        return AdTrafficRiskDecision::allow();
    }

    private function isHighRisk(?FraudRiskFeature $feature): bool
    {
        return $feature !== null && $feature->riskBucket === 'high';
    }
}

