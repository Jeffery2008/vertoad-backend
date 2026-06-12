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
        'GET:/api/v1/oauth/authorize' => true,
        'POST:/api/v1/oauth/consent' => true,
    ];

    /**
     * @param list<string> $protectedEndpoints
     */
    public function __construct(
        public bool $enabled,
        public int $timeoutSeconds,
        public array $protectedEndpoints,
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
            protectedEndpoints: [self::WILDCARD_ENDPOINT],
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
            protectedEndpoints: self::requiredEndpointList($value),
        );
    }

    public function protects(string $method, string $path): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (in_array(self::WILDCARD_ENDPOINT, $this->protectedEndpoints, true)) {
            return true;
        }

        $endpoint = strtoupper(trim($method)) . ':' . $path;

        return in_array($endpoint, $this->protectedEndpoints, true);
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
    private static function requiredEndpointList(array $value): array
    {
        $endpoints = $value['protected_endpoints'] ?? null;
        if (!is_array($endpoints) || !array_is_list($endpoints)) {
            throw new InvalidArgumentException('protected_endpoints must be a list.');
        }

        $normalized = [];
        foreach ($endpoints as $endpoint) {
            if (!is_string($endpoint)) {
                throw new InvalidArgumentException('protected_endpoints entries must be strings.');
            }

            $normalizedEndpoint = self::normalizeEndpoint($endpoint);
            if (!isset($normalized[$normalizedEndpoint])) {
                $normalized[$normalizedEndpoint] = $normalizedEndpoint;
            }
        }

        return array_values($normalized);
    }

    private static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === self::WILDCARD_ENDPOINT) {
            throw new InvalidArgumentException('protected_endpoints must list explicit high-risk endpoints.');
        }

        $parts = explode(':', $endpoint, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || !str_starts_with($parts[1], '/')) {
            throw new InvalidArgumentException('protected_endpoints entries must use METHOD:/path.');
        }

        $normalized = strtoupper(trim($parts[0])) . ':' . $parts[1];
        if (!isset(self::ALLOWED_ENDPOINTS[$normalized])) {
            throw new InvalidArgumentException('protected_endpoints contains unsupported endpoint ' . $normalized . '.');
        }

        return $normalized;
    }

}
