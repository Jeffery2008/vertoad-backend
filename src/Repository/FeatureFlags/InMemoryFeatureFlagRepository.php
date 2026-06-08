<?php

declare(strict_types=1);

namespace VertoAD\Repository\FeatureFlags;

use VertoAD\Domain\FeatureFlags\FeatureFlag;

final class InMemoryFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    /** @var array<string, FeatureFlag> */
    private array $flags = [];

    public function save(FeatureFlag $flag): FeatureFlag
    {
        $this->flags[$flag->flag_key] = $flag;

        return $flag;
    }

    public function find(string $flagKey): ?FeatureFlag
    {
        return $this->flags[$flagKey] ?? null;
    }

    public function all(): array
    {
        return array_values($this->flags);
    }
}
