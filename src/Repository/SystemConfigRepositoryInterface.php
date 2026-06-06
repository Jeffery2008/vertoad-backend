<?php

declare(strict_types=1);

namespace VertoAD\Repository;

interface SystemConfigRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findLatestValue(string $configKey): ?array;
}
