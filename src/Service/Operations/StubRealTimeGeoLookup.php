<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class StubRealTimeGeoLookup implements RealTimeGeoLookupInterface
{
    public function lookup(string $ipAddress, ?string $requestId = null): array
    {
        $ipAddress = trim($ipAddress);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('ip_address must be a valid IP address.');
        }

        return [
            'ip_address' => $ipAddress,
            'canonical_geo_code' => null,
            'country_code' => null,
            'region_code' => null,
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'provider_id' => null,
            'source' => 'operations-stub',
            'queried_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'persisted_to_canonical_store' => false,
        ];
    }
}
