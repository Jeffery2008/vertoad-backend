<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use Closure;
use JsonException;
use RuntimeException;
use VertoAD\Domain\Serving\ServingGeoTargetingPolicy;

final readonly class PconlineGeoProvider implements GeoProviderInterface
{
    private Closure $transport;

    /**
     * @param null|callable(string, int):array{status:int,body:string}|callable(string, array<string, string>, int):array{status:int,body:string} $transport
     */
    public function __construct(
        private ServingGeoTargetingPolicy $policy,
        ?callable $transport = null,
    ) {
        $this->transport = Closure::fromCallable($transport ?? self::httpTransport());
    }

    public function lookup(?string $ipAddress, ?string $userAgent = null): ?string
    {
        $ipAddress = $this->normalizeIp($ipAddress);
        if ($ipAddress === null) {
            return null;
        }

        $response = ($this->transport)($this->policy->endpoint . '?ip=' . rawurlencode($ipAddress) . '&json=true', $this->policy->timeoutSeconds);
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300 || trim($body) === '') {
            throw new RuntimeException('Geo provider request failed.');
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Geo provider returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Geo provider returned invalid JSON.');
        }

        return $this->canonicalCode($decoded);
    }

    /**
     * @return callable(string, int):array{status:int,body:string}
     */
    public static function httpTransport(): callable
    {
        return static function (string $url, int $timeoutSeconds): array {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSeconds,
                    'ignore_errors' => true,
                    'header' => "User-Agent: VertoAD-Geo-Resolve/1.0\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $context);

            return [
                'status' => self::statusCodeFromHeaders($http_response_header ?? [], $body),
                'body' => $body === false ? '' : $body,
            ];
        };
    }

    /**
     * @param list<string> $headers
     */
    public static function statusCodeFromHeaders(array $headers, string|false $body): int
    {
        if (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return $body === false ? 0 : 200;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function canonicalCode(array $decoded): ?string
    {
        $province = $this->provinceCode($decoded['proCode'] ?? $decoded['pro'] ?? $decoded['province'] ?? null);
        $city = $this->cityCode($decoded['cityCode'] ?? $decoded['city'] ?? null, $province);
        $country = $this->normalizePart($decoded['countryCode'] ?? $decoded['country'] ?? null);

        if ($province !== null || $city !== null) {
            $parts = array_filter([$country ?? $this->policy->defaultCountryCode, $province, $city], static fn (?string $value): bool => $value !== null && $value !== '');
            if ($parts !== []) {
                return implode('-', $parts);
            }
        }

        $code = $this->normalizePart($decoded['code'] ?? null);
        if ($code !== null) {
            return $code;
        }

        return null;
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

    private function normalizePart(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '-', $value) ?? $value);
    }

    private function provinceCode(mixed $value): ?string
    {
        $normalized = $this->normalizePart($value);
        if ($normalized === null) {
            return null;
        }

        $aliases = [
            '安徽' => 'AH',
            '安徽省' => 'AH',
            '北京' => 'BJ',
            '北京市' => 'BJ',
            '重庆' => 'CQ',
            '重庆市' => 'CQ',
            '福建' => 'FJ',
            '福建省' => 'FJ',
            '甘肃' => 'GS',
            '甘肃省' => 'GS',
            '广东' => 'GD',
            '广东省' => 'GD',
            '广西' => 'GX',
            '广西壮族自治区' => 'GX',
            '贵州' => 'GZ',
            '贵州省' => 'GZ',
            '海南' => 'HI',
            '海南省' => 'HI',
            '河北' => 'HE',
            '河北省' => 'HE',
            '黑龙江' => 'HL',
            '黑龙江省' => 'HL',
            '河南' => 'HA',
            '河南省' => 'HA',
            '湖北' => 'HB',
            '湖北省' => 'HB',
            '湖南' => 'HN',
            '湖南省' => 'HN',
            '内蒙古' => 'NM',
            '内蒙古自治区' => 'NM',
            '江苏' => 'JS',
            '江苏省' => 'JS',
            '江西' => 'JX',
            '江西省' => 'JX',
            '吉林' => 'JL',
            '吉林省' => 'JL',
            '辽宁' => 'LN',
            '辽宁省' => 'LN',
            '宁夏' => 'NX',
            '宁夏回族自治区' => 'NX',
            '青海' => 'QH',
            '青海省' => 'QH',
            '陕西' => 'SN',
            '陕西省' => 'SN',
            '山东' => 'SD',
            '山东省' => 'SD',
            '上海' => 'SH',
            '上海市' => 'SH',
            '山西' => 'SX',
            '山西省' => 'SX',
            '四川' => 'SC',
            '四川省' => 'SC',
            '天津' => 'TJ',
            '天津市' => 'TJ',
            '西藏' => 'XZ',
            '西藏自治区' => 'XZ',
            '新疆' => 'XJ',
            '新疆维吾尔自治区' => 'XJ',
            '云南' => 'YN',
            '云南省' => 'YN',
            '浙江' => 'ZJ',
            '浙江省' => 'ZJ',
            '香港' => 'HK',
            '香港特别行政区' => 'HK',
            '澳门' => 'MO',
            '澳门特别行政区' => 'MO',
            '台湾' => 'TW',
            '台湾省' => 'TW',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private function cityCode(mixed $value, ?string $province): ?string
    {
        $normalized = $this->normalizePart($value);
        if ($normalized === null) {
            return null;
        }

        $municipalities = [
            'BJ' => ['北京', '北京市', 'BJ'],
            'CQ' => ['重庆', '重庆市', 'CQ'],
            'SH' => ['上海', '上海市', 'SH'],
            'TJ' => ['天津', '天津市', 'TJ'],
        ];
        if ($province !== null && in_array($normalized, $municipalities[$province] ?? [], true)) {
            return null;
        }

        return $normalized;
    }
}
