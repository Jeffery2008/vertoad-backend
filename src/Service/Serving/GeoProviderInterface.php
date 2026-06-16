<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

interface GeoProviderInterface
{
    public function lookup(?string $ipAddress, ?string $userAgent = null): ?string;
}
