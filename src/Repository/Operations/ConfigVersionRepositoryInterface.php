<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use VertoAD\Domain\Operations\ConfigVersion;

interface ConfigVersionRepositoryInterface
{
    public function append(ConfigVersion $version): ConfigVersion;

    public function find(string $versionId): ?ConfigVersion;

    /**
     * @return list<ConfigVersion>
     */
    public function listByKey(string $configKey): array;

    public function nextVersionNumber(string $configKey): int;
}
