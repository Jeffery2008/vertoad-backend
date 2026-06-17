<?php

declare(strict_types=1);

namespace VertoAD\Service\IpGeo;

use Closure;
use DateTimeImmutable;
use JsonException;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\IpGeo\IpGeoProviderDefinition;

final readonly class MappedHttpIpGeoProviderClient
{
    private Closure $transport;

    /**
     * @param null|callable(string, array<string, string>, int):array{status:int,body:string} $transport
     */
    public function __construct(
        private MappedIpGeoResponseNormalizer $normalizer,
        ?callable $transport = null,
    ) {
        $this->transport = Closure::fromCallable($transport ?? self::httpTransport());
    }

    public function lookup(
        string $ipAddress,
        IpGeoProviderDefinition $provider,
        DateTimeImmutable $resolvedAt,
        ?string $defaultCountryCode = null,
    ): GeoIpRecord
    {
        $apiKey = $provider->apiKeyEnvVar === null ? null : getenv($provider->apiKeyEnvVar);
        if ($this->requiresApiKey($provider) && (!is_string($apiKey) || trim($apiKey) === '')) {
            throw new \RuntimeException('IP geo provider ' . $provider->id . ' requires api_key_env_var ' . ($provider->apiKeyEnvVar ?? '(unset)') . '.');
        }

        $response = ($this->transport)(
            $provider->endpointForIp($ipAddress, is_string($apiKey) ? $apiKey : null),
            $this->headers($provider, is_string($apiKey) ? $apiKey : null),
            $provider->timeoutSeconds,
        );
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300 || trim($body) === '') {
            throw new \RuntimeException('IP geo provider ' . $provider->id . ' failed with HTTP ' . $status . '.');
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('IP geo provider ' . $provider->id . ' returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('IP geo provider ' . $provider->id . ' returned invalid JSON.');
        }

        return $this->normalizer->normalize($ipAddress, $provider, $decoded, $resolvedAt, $defaultCountryCode);
    }

    /**
     * @return callable(string, array<string, string>, int):array{status:int,body:string}
     */
    public static function httpTransport(): callable
    {
        return static function (string $url, array $headers, int $timeoutSeconds): array {
            $headerLines = ["User-Agent: VertoAD-IP-Geo/1.0"];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSeconds,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headerLines) . "\r\n",
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
     * @return array<string, string>
     */
    private function headers(IpGeoProviderDefinition $provider, ?string $apiKey): array
    {
        $headers = $provider->headers;
        if ($apiKey !== null && $apiKey !== '') {
            foreach ($headers as $name => $value) {
                $headers[$name] = str_replace('{api_key}', $apiKey, $value);
            }
        }

        return $headers;
    }

    private function requiresApiKey(IpGeoProviderDefinition $provider): bool
    {
        if (str_contains($provider->endpointTemplate, '{api_key}')) {
            return true;
        }

        foreach ($provider->headers as $value) {
            if (str_contains($value, '{api_key}')) {
                return true;
            }
        }

        return false;
    }
}
