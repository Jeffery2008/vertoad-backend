<?php

declare(strict_types=1);

namespace VertoAD\Domain\IpGeo;

use DateTimeImmutable;

final readonly class GeoIpRecord
{
    /**
     * @param array<string, mixed> $rawPayloadSummary
     */
    public function __construct(
        public string $ipAddress,
        public ?string $countryCode,
        public ?string $countryName,
        public ?string $regionCode,
        public ?string $regionName,
        public ?string $cityName,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $timezone,
        public string $providerId,
        public DateTimeImmutable $resolvedAt,
        public string $rawPayloadHash,
        public array $rawPayloadSummary,
    ) {
        if (@inet_pton($ipAddress) === false) {
            throw new \InvalidArgumentException('Geo IP record requires a valid IP address.');
        }

        if (trim($providerId) === '') {
            throw new \InvalidArgumentException('Geo IP record provider id is required.');
        }

        if (trim($rawPayloadHash) === '') {
            throw new \InvalidArgumentException('Geo IP record raw payload hash is required.');
        }
    }

    public function ipHash(): string
    {
        return self::hashIp($this->ipAddress);
    }

    public function canonicalGeoCode(): ?string
    {
        $countryCode = $this->normalizeCode($this->countryCode);
        $regionCode = $this->normalizeCode($this->regionCode);
        $cityName = $this->normalizeName($this->cityName);

        if ($countryCode === null) {
            return null;
        }

        $parts = [$countryCode];
        if ($regionCode !== null) {
            $parts[] = $regionCode;
        }
        if ($cityName !== null && $cityName !== $regionCode) {
            $parts[] = $cityName;
        }

        return implode('-', $parts);
    }

    public static function hashIp(string $ipAddress): string
    {
        $normalized = trim($ipAddress);
        $packed = @inet_pton($normalized);
        if ($packed === false) {
            throw new \InvalidArgumentException('Cannot hash invalid IP address.');
        }

        return hash('sha256', $packed);
    }

    private function normalizeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value === '' ? null : preg_replace('/\s+/', '-', $value);
    }

    private function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value === '' ? null : preg_replace('/\s+/', '-', $value);
    }
}
