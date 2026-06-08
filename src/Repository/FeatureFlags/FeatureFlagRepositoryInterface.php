<?php

declare(strict_types=1);

namespace VertoAD\Repository\FeatureFlags;

use VertoAD\Domain\FeatureFlags\FeatureFlag;

interface FeatureFlagRepositoryInterface
{
    public function save(FeatureFlag $flag): FeatureFlag;

    public function find(string $flagKey): ?FeatureFlag;

    /**
     * @return list<FeatureFlag>
     */
    public function all(): array;
}
