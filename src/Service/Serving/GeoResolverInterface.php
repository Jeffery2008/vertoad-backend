<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use VertoAD\Domain\Serving\ServingRequestContext;

interface GeoResolverInterface
{
    public function resolve(?string $ipAddress, ?string $userAgent = null): ?string;

    public function contextForRequest(?string $ipAddress, ?string $userAgent = null, ?string $requestId = null): ServingRequestContext;
}
