<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\ConfigVersion;

final class InMemoryConfigVersionRepository implements ConfigVersionRepositoryInterface
{
    /** @var array<string, ConfigVersion> */
    private array $versions = [];

    public function append(ConfigVersion $version): ConfigVersion
    {
        $this->versions[$version->version_id] = $version;

        return $version;
    }

    public function find(string $versionId): ?ConfigVersion
    {
        return $this->versions[$versionId] ?? null;
    }

    public function listByKey(string $configKey): array
    {
        $versions = array_values(array_filter(
            $this->versions,
            static fn (ConfigVersion $version): bool => $version->config_key === $configKey,
        ));
        usort($versions, static fn (ConfigVersion $left, ConfigVersion $right): int => $left->version_number <=> $right->version_number);

        return $versions;
    }

    public function nextVersionNumber(string $configKey): int
    {
        $last = 0;
        foreach ($this->listByKey($configKey) as $version) {
            $last = max($last, $version->version_number);
        }

        return $last + 1;
    }
}
