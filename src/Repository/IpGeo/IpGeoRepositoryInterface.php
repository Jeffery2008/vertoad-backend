<?php

declare(strict_types=1);

namespace VertoAD\Repository\IpGeo;

use DateTimeImmutable;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Domain\IpGeo\GeoIpRecord;

interface IpGeoRepositoryInterface
{
    public function findResolved(string $ipAddress): ?GeoIpRecord;

    public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void;

    /**
     * @param array{
     *     request_id?: string|null,
     *     ip_address?: string|null,
     *     status?: string|null,
     *     source?: string|null,
     *     occurred_from?: string|null,
     *     occurred_to?: string|null,
     *     limit?: int
     * } $filters
     * @return list<GeoIpLookupTask>
     */
    public function searchLookups(array $filters): array;

    /**
     * @return list<GeoIpLookupTask>
     */
    public function leasePending(int $limit, DateTimeImmutable $now): array;

    public function markResolved(GeoIpRecord $record): void;

    public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds): void;
}
