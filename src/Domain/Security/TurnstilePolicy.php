<?php

declare(strict_types=1);

namespace VertoAD\Domain\Security;

use InvalidArgumentException;

final readonly class TurnstilePolicy
{
    private const MAX_TIMEOUT_SECONDS = 30;
    private const WILDCARD_ENDPOINT = '*';
    private const ALLOWED_ENDPOINTS = [
        'POST:/api/v1/auth/register' => true,
        'POST:/api/v1/auth/login' => true,
        'POST:/api/v1/auth/password-reset/request' => true,
        'POST:/api/v1/auth/password-reset/confirm' => true,
        'POST:/api/v1/billing/recharge-keys/redeem' => true,
        'POST:/api/v1/oauth/consent' => true,
    ];
    private const CONDITIONAL_ALLOWED_ENDPOINTS = [
        'POST:/api/v1/ads/track' => true,
        'GET:/api/v1/ads/click' => true,
    ];

    /**
     * @param list<string> $protectedEndpoints
     * @param list<string> $conditionalProtectedEndpoints
     */
    public function __construct(
        public bool $enabled,
        public int $timeoutSeconds,
        public array $protectedEndpoints,
        public array $conditionalProtectedEndpoints = [],
    ) {
        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('timeout_seconds must be between 1 and ' . self::MAX_TIMEOUT_SECONDS . '.');
        }

        if ($this->protectedEndpoints === []) {
            throw new InvalidArgumentException('protected_endpoints must not be empty.');
        }

    }

    public static function default(): self
    {
        return new self(
            enabled: true,
            timeoutSeconds: 5,
            protectedEndpoints: array_keys(self::ALLOWED_ENDPOINTS),
            conditionalProtectedEndpoints: array_keys(self::CONDITIONAL_ALLOWED_ENDPOINTS),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $allowedKeys = [
            'enabled' => true,
            'timeout_seconds' => true,
            'protected_endpoints' => true,
            'conditional_protected_endpoints' => true,
        ];
        foreach ($value as $key => $_) {
            if (!isset($allowedKeys[(string) $key])) {
                throw new InvalidArgumentException('unknown field ' . (string) $key . '.');
            }
        }

        $enabled = $value['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('enabled must be a boolean.');
        }

        return new self(
            enabled: $enabled,
            timeoutSeconds: self::requiredInt($value, 'timeout_seconds'),
            protectedEndpoints: self::requiredEndpointList($value, 'protected_endpoints', self::ALLOWED_ENDPOINTS, allowWildcard: false),
            conditionalProtectedEndpoints: self::optionalEndpointList(
                $value,
                'conditional_protected_endpoints',
                self::CONDITIONAL_ALLOWED_ENDPOINTS,
            ),
        );
    }

    public function protects(string $method, string $path): bool
    {
        return $this->protectsRequest($method, $path, false);
    }

    public function protectsRequest(string $method, string $path, bool $abnormalTraffic): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->matchesRequest($method, $path, $abnormalTraffic);
    }

    public function matchesRequest(string $method, string $path, bool $abnormalTraffic): bool
    {
        if (in_array(self::WILDCARD_ENDPOINT, $this->protectedEndpoints, true)) {
            return true;
        }

        $endpoint = strtoupper(trim($method)) . ':' . $path;
        if (in_array($endpoint, $this->protectedEndpoints, true)) {
            return true;
        }

        return $abnormalTraffic && in_array($endpoint, $this->conditionalProtectedEndpoints, true);
    }

    public function conditionallyProtects(string $method, string $path): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $endpoint = strtoupper(trim($method)) . ':' . $path;

        return in_array($endpoint, $this->conditionalProtectedEndpoints, true);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function requiredInt(array $value, string $key): int
    {
        $item = $value[$key] ?? null;
        if (!is_int($item)) {
            throw new InvalidArgumentException($key . ' must be an integer.');
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $value
     * @return list<string>
     */
    private static function requiredEndpointList(array $value, string $key, array $allowedEndpoints, bool $allowWildcard): array
    {
        $endpoints = $value[$key] ?? null;
        if (!is_array($endpoints) || !array_is_list($endpoints)) {
            throw new InvalidArgumentException($key . ' must be a list.');
        }

        if ($endpoints === []) {
            throw new InvalidArgumentException($key . ' must not be empty.');
        }

        return self::endpointList($endpoints, $key, $allowedEndpoints, $allowWildcard);
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, true> $allowedEndpoints
     * @return list<string>
     */
    private static function optionalEndpointList(array $value, string $key, array $allowedEndpoints): array
    {
        if (!array_key_exists($key, $value)) {
            return [];
        }

        $endpoints = $value[$key];
        if (!is_array($endpoints) || !array_is_list($endpoints)) {
            throw new InvalidArgumentException($key . ' must be a list.');
        }

        return self::endpointList($endpoints, $key, $allowedEndpoints, allowWildcard: false);
    }

    /**
     * @param list<mixed> $endpoints
     * @param array<string, true> $allowedEndpoints
     * @return list<string>
     */
    private static function endpointList(array $endpoints, string $key, array $allowedEndpoints, bool $allowWildcard): array
    {
        $normalized = [];
        foreach ($endpoints as $endpoint) {
            if (!is_string($endpoint)) {
                throw new InvalidArgumentException($key . ' entries must be strings.');
            }

            $normalizedEndpoint = self::normalizeEndpoint($endpoint, $key, $allowedEndpoints, $allowWildcard);
            if (!isset($normalized[$normalizedEndpoint])) {
                $normalized[$normalizedEndpoint] = $normalizedEndpoint;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, true> $allowedEndpoints
     */
    private static function normalizeEndpoint(
        string $endpoint,
        string $key,
        array $allowedEndpoints,
        bool $allowWildcard,
    ): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === self::WILDCARD_ENDPOINT) {
            if ($allowWildcard) {
                return self::WILDCARD_ENDPOINT;
            }

            throw new InvalidArgumentException($key . ' must list explicit high-risk endpoints.');
        }

        $parts = explode(':', $endpoint, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || !str_starts_with($parts[1], '/')) {
            throw new InvalidArgumentException($key . ' entries must use METHOD:/path.');
        }

        $normalized = strtoupper(trim($parts[0])) . ':' . $parts[1];
        if (!isset($allowedEndpoints[$normalized])) {
            throw new InvalidArgumentException($key . ' contains unsupported endpoint ' . $normalized . '.');
        }

        return $normalized;
    }

}
