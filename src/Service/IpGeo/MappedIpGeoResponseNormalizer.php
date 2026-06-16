<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use DateTimeImmutable;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;

final class MappedIpGeoResponseNormalizer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(
        string $ipAddress,
        IpGeoProviderDefinition $provider,
        array $payload,
        DateTimeImmutable $resolvedAt,
    ): GeoIpRecord {
        $summary = [];
        foreach ($provider->fieldMap as $canonicalField => $path) {
            $summary[$path] = $this->valueAtPath($payload, $path);
        }

        $countryCode = $this->code($summary[$provider->fieldMap['country_code'] ?? ''] ?? null);
        $regionCode = $this->code($summary[$provider->fieldMap['region_code'] ?? ''] ?? null);
        if ($countryCode === null && $provider->id === 'pconline') {
            $countryCode = 'CN';
        }
        if ($provider->id === 'pconline') {
            $regionCode = $this->chinaRegionCode($summary[$provider->fieldMap['region_code'] ?? ''] ?? $summary[$provider->fieldMap['region_name'] ?? ''] ?? null);
        }

        return new GeoIpRecord(
            ipAddress: $ipAddress,
            countryCode: $countryCode,
            countryName: $this->string($summary[$provider->fieldMap['country_name'] ?? ''] ?? null),
            regionCode: $regionCode,
            regionName: $this->string($summary[$provider->fieldMap['region_name'] ?? ''] ?? null),
            cityName: $this->string($summary[$provider->fieldMap['city_name'] ?? ''] ?? null),
            latitude: $this->float($summary[$provider->fieldMap['latitude'] ?? ''] ?? null),
            longitude: $this->float($summary[$provider->fieldMap['longitude'] ?? ''] ?? null),
            timezone: $this->string($summary[$provider->fieldMap['timezone'] ?? ''] ?? null),
            providerId: $provider->id,
            resolvedAt: $resolvedAt,
            rawPayloadHash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            rawPayloadSummary: $summary,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function code(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : strtoupper($value);
    }

    private function float(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function chinaRegionCode(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        $numeric = [
            '110000' => 'BJ',
            '120000' => 'TJ',
            '310000' => 'SH',
            '500000' => 'CQ',
            '440000' => 'GD',
            '440100' => 'GD',
            '330000' => 'ZJ',
            '320000' => 'JS',
        ];
        if (isset($numeric[$value])) {
            return $numeric[$value];
        }

        $aliases = [
            '北京' => 'BJ',
            '北京市' => 'BJ',
            '上海' => 'SH',
            '上海市' => 'SH',
            '广东' => 'GD',
            '广东省' => 'GD',
            '浙江' => 'ZJ',
            '浙江省' => 'ZJ',
            '江苏' => 'JS',
            '江苏省' => 'JS',
        ];

        return $aliases[$value] ?? strtoupper($value);
    }
}
