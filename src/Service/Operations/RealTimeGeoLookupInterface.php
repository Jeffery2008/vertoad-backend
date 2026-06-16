<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

interface RealTimeGeoLookupInterface
{
    /**
     * @return array<string, mixed>
     */
    public function lookup(string $ipAddress, ?string $requestId = null): array;
}
