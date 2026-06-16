<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use DateTimeImmutable;
use Throwable;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Service\Serving\GeoResolverInterface;

final readonly class AsyncIpGeoResolver implements GeoResolverInterface, IpGeoRequestContextResolverInterface
{
    public function __construct(
        private IpGeoRepositoryInterface $repository,
        private string $source = 'serving',
        private ?string $regionHint = null,
    ) {
    }

    public function resolve(?string $ipAddress, ?string $userAgent = null): ?string
    {
        return $this->contextForRequest($ipAddress, $userAgent)->geoCode;
    }

    public function contextForRequest(?string $ipAddress, ?string $userAgent = null, ?string $requestId = null): ServingRequestContext
    {
        return $this->contextForIpGeoRequest($ipAddress, $userAgent, $this->regionHint, $requestId);
    }

    public function contextForIpGeoRequest(
        ?string $ipAddress,
        ?string $userAgent = null,
        ?string $regionHint = null,
        ?string $requestId = null,
    ): ServingRequestContext {
        $ipAddress = $this->normalizeIp($ipAddress);
        if ($ipAddress === null) {
            return new ServingRequestContext(null, $userAgent, null, null, $requestId);
        }
        $requestId ??= RequestIdContext::current();

        $record = null;
        try {
            $record = $this->repository->findResolved($ipAddress);
        } catch (Throwable) {
            return new ServingRequestContext($ipAddress, $userAgent, null, null, $requestId);
        }

        if ($record === null) {
            try {
                $this->repository->ensureQueued($ipAddress, $userAgent, $regionHint ?? $this->regionHint, $this->source, new DateTimeImmutable(), $requestId);
            } catch (Throwable) {
            }
        }

        return new ServingRequestContext($ipAddress, $userAgent, $record?->canonicalGeoCode(), $record, $requestId);
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        if (!is_string($ipAddress)) {
            return null;
        }

        $ipAddress = trim($ipAddress);
        if ($ipAddress === '' || @inet_pton($ipAddress) === false) {
            return null;
        }

        return $ipAddress;
    }
}
