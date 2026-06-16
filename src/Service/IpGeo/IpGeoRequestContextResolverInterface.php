<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use VertoAD\Domain\Serving\ServingRequestContext;

interface IpGeoRequestContextResolverInterface
{
    public function contextForIpGeoRequest(
        ?string $ipAddress,
        ?string $userAgent = null,
        ?string $regionHint = null,
        ?string $requestId = null,
    ): ServingRequestContext;
}
