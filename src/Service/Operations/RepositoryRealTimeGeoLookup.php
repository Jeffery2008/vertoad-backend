<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;

final readonly class RepositoryRealTimeGeoLookup implements RealTimeGeoLookupInterface
{
    public function __construct(
        private IpGeoRepositoryInterface $repository,
        private IpGeoProviderSelector $selector,
        private MappedHttpIpGeoProviderClient $client,
        private IpGeoProviderPolicy $policy,
    ) {
    }

    public function lookup(string $ipAddress, ?string $requestId = null): array
    {
        if (@inet_pton($ipAddress) === false) {
            throw new \InvalidArgumentException('ip_address must be a valid IP address.');
        }

        $record = $this->repository->findResolved($ipAddress);
        if ($record === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($this->policy->enabled) {
                try {
                    $this->repository->ensureQueued($ipAddress, null, null, 'operations_realtime_lookup', $now, $requestId);
                    foreach ($this->selector->orderedProviders(null, $ipAddress) as $provider) {
                        try {
                            $record = $this->client->lookup($ipAddress, $provider, $now, $this->policy->defaultCountryCode);
                            $this->repository->markResolved($record);

                            return [
                                ...$this->recordPayload($record),
                                'source' => 'provider',
                                'status' => 'resolved',
                                'queried_at' => $now->format(DATE_ATOM),
                                'persisted_to_canonical_store' => true,
                            ];
                        } catch (Throwable) {
                        }
                    }
                } catch (Throwable) {
                    // Realtime admin lookups must not fail the log page when providers are down.
                }
            }

            $this->repository->ensureQueued($ipAddress, null, null, 'operations_realtime_lookup', $now, $requestId);

            return [
                'ip_address' => $ipAddress,
                'canonical_geo_code' => null,
                'country_code' => null,
                'country_name' => null,
                'region_code' => null,
                'region' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
                'timezone' => null,
                'provider_id' => null,
                'source' => 'queued',
                'status' => 'pending',
                'queried_at' => $now->format(DATE_ATOM),
                'persisted_to_canonical_store' => false,
            ];
        }

        return [
            ...$this->recordPayload($record),
            'source' => 'canonical_store',
            'status' => 'resolved',
            'queried_at' => $record->resolvedAt->format(DATE_ATOM),
            'persisted_to_canonical_store' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(\VertoAD\Domain\IpGeo\GeoIpRecord $record): array
    {
        return [
            'ip_address' => $record->ipAddress,
            'canonical_geo_code' => $record->canonicalGeoCode(),
            'country_code' => $record->countryCode,
            'country_name' => $record->countryName,
            'region_code' => $record->regionCode,
            'region' => $record->regionName,
            'city' => $record->cityName,
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'timezone' => $record->timezone,
            'provider_id' => $record->providerId,
        ];
    }
}
