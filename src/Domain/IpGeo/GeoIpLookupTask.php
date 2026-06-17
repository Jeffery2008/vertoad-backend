<?php

declare(strict_types=1);

namespace VertoAD\Domain\IpGeo;

use DateTimeImmutable;

final readonly class GeoIpLookupTask
{
    private const array STATUSES = ['pending', 'processing', 'failed', 'dead', 'resolved'];

    /**
     * @param list<string> $requestIds
     */
    public function __construct(
        public string $ipAddress,
        public ?string $userAgent,
        public ?string $regionHint,
        public int $attempts,
        public DateTimeImmutable $createdAt,
        public ?string $requestId = null,
        public array $requestIds = [],
        public string $source = 'unknown',
        public string $status = 'pending',
        public ?string $providerId = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $resolvedAt = null,
        public ?string $leaseToken = null,
    ) {
        if (@inet_pton($ipAddress) === false) {
            throw new \InvalidArgumentException('IP geo lookup task requires a valid IP address.');
        }

        if ($attempts < 0) {
            throw new \InvalidArgumentException('IP geo lookup attempts must be non-negative.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('IP geo lookup task status is not supported.');
        }

        foreach ($requestIds as $requestIdValue) {
            if (!is_string($requestIdValue) || trim($requestIdValue) === '') {
                throw new \InvalidArgumentException('IP geo lookup task request IDs must be non-empty strings.');
            }
        }

        if ($leaseToken !== null && trim($leaseToken) === '') {
            throw new \InvalidArgumentException('IP geo lookup task lease token must be non-empty when present.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'region_hint' => $this->regionHint,
            'request_id' => $this->requestId,
            'request_ids' => $this->requestIds,
            'source' => $this->source,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'provider_id' => $this->providerId,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'next_attempt_at' => $this->nextAttemptAt?->format(DATE_ATOM),
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
            'lease_token' => $this->leaseToken,
        ];
    }
}
