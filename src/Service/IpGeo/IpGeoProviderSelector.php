<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;

final readonly class IpGeoProviderSelector
{
    public function __construct(private IpGeoProviderPolicy $policy)
    {
    }

    public function select(?string $regionHint, string $ipAddress): IpGeoProviderDefinition
    {
        $providers = $this->policy->providersForRegion($regionHint);
        if ($providers === []) {
            throw new \RuntimeException('No IP geo provider is configured for region.');
        }

        $totalWeight = array_sum(array_map(static fn (IpGeoProviderDefinition $provider): int => $provider->weight, $providers));
        $slot = $this->slot($regionHint, $ipAddress, $totalWeight);
        $cursor = 0;
        foreach ($providers as $provider) {
            $cursor += $provider->weight;
            if ($slot < $cursor) {
                return $provider;
            }
        }

        return $providers[array_key_last($providers)];
    }

    private function slot(?string $regionHint, string $ipAddress, int $totalWeight): int
    {
        if ($totalWeight < 1) {
            throw new \RuntimeException('IP geo provider weights must be positive.');
        }

        $hash = hash('sha256', strtoupper((string) $regionHint) . '|' . $ipAddress);
        $value = (int) hexdec(substr($hash, 0, 8));

        return $value % $totalWeight;
    }
}
