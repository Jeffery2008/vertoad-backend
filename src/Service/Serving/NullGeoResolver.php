<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use VertoAD\Domain\Serving\ServingRequestContext;

final readonly class NullGeoResolver implements GeoResolverInterface
{
    public function resolve(?string $ipAddress, ?string $userAgent = null): ?string
    {
        return null;
    }

    public function contextForRequest(?string $ipAddress, ?string $userAgent = null, ?string $requestId = null): ServingRequestContext
    {
        return new ServingRequestContext($ipAddress, $userAgent, null, null, $requestId);
    }
}
