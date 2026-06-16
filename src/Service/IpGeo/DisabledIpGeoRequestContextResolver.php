<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use VertoAD\Domain\Serving\ServingRequestContext;

final readonly class DisabledIpGeoRequestContextResolver implements IpGeoRequestContextResolverInterface
{
    public function contextForIpGeoRequest(
        ?string $ipAddress,
        ?string $userAgent = null,
        ?string $regionHint = null,
        ?string $requestId = null,
    ): ServingRequestContext {
        return new ServingRequestContext($this->normalizeIp($ipAddress), $userAgent, null, null, $requestId);
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        if (!is_string($ipAddress)) {
            return null;
        }

        $ipAddress = trim($ipAddress);

        return $ipAddress !== '' && @inet_pton($ipAddress) !== false ? $ipAddress : null;
    }
}
