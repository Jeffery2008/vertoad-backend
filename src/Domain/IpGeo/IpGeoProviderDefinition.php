<?php

declare(strict_types=1);

namespace VertoAD\Domain\IpGeo;

final readonly class IpGeoProviderDefinition
{
    public string $id;
    public string $endpointTemplate;
    /** @var array<string, string> */
    public array $fieldMap;
    /** @var list<string> */
    public array $regions;
    public int $weight;
    public int $timeoutSeconds;
    public ?string $apiKeyEnvVar;
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param array<string, string> $fieldMap
     * @param list<string> $regions
     * @param array<string, string> $headers
     */
    public function __construct(
        string $id,
        string $endpointTemplate,
        array $fieldMap,
        array $regions = ['global'],
        int $weight = 1,
        int $timeoutSeconds = 2,
        ?string $apiKeyEnvVar = null,
        array $headers = [],
    ) {
        $id = trim($id);
        $endpointTemplate = trim($endpointTemplate);
        $regions = array_values(array_map(static fn (string $region): string => strtoupper(trim($region)), $regions));
        $apiKeyEnvVar = $apiKeyEnvVar === null ? null : trim($apiKeyEnvVar);

        if ($id === '') {
            throw new \InvalidArgumentException('IP geo provider id is required.');
        }

        if ($endpointTemplate === '' || !str_starts_with(strtolower($endpointTemplate), 'https://')) {
            throw new \InvalidArgumentException('IP geo provider endpoint_template must be an HTTPS URL template.');
        }

        if ($fieldMap === []) {
            throw new \InvalidArgumentException('IP geo provider fields are required.');
        }

        if ($weight < 1) {
            throw new \InvalidArgumentException('IP geo provider weight must be positive.');
        }

        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('IP geo provider timeout_seconds must be positive.');
        }

        if ($apiKeyEnvVar !== null && preg_match('/^[A-Z][A-Z0-9_]*$/', $apiKeyEnvVar) !== 1) {
            throw new \InvalidArgumentException('IP geo provider api_key_env_var must be an environment variable name.');
        }

        $this->id = $id;
        $this->endpointTemplate = $endpointTemplate;
        $this->fieldMap = $fieldMap;
        $this->regions = $regions;
        $this->weight = $weight;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->apiKeyEnvVar = $apiKeyEnvVar;
        $this->headers = $headers;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $allowedKeys = array_fill_keys([
            'api_key_env_var',
            'id',
            'endpoint_template',
            'endpoint',
            'fields',
            'field_map',
            'regions',
            'weight',
            'timeout_seconds',
            'headers',
        ], true);

        foreach ($value as $key => $_) {
            if (self::isPlaintextSecretField((string) $key)) {
                throw new \InvalidArgumentException('Provider API keys must stay in environment variables.');
            }
            if (!isset($allowedKeys[(string) $key])) {
                throw new \InvalidArgumentException('IP geo provider contains unknown field ' . (string) $key . '.');
            }
        }

        $fields = $value['fields'] ?? $value['field_map'] ?? [];
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('IP geo provider fields must be an object.');
        }

        $regions = $value['regions'] ?? ['global'];
        if (!is_array($regions) || $regions === []) {
            throw new \InvalidArgumentException('IP geo provider regions must be a non-empty list.');
        }

        $headers = $value['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('IP geo provider headers must be an object.');
        }

        return new self(
            id: (string) ($value['id'] ?? ''),
            endpointTemplate: (string) ($value['endpoint_template'] ?? $value['endpoint'] ?? ''),
            fieldMap: self::stringMap($fields, 'fields'),
            regions: array_values(array_map(static fn (mixed $region): string => strtoupper(trim((string) $region)), $regions)),
            weight: (int) ($value['weight'] ?? 1),
            timeoutSeconds: (int) ($value['timeout_seconds'] ?? 2),
            apiKeyEnvVar: isset($value['api_key_env_var']) ? (string) $value['api_key_env_var'] : null,
            headers: self::stringMap($headers, 'headers'),
        );
    }

    public function endpointForIp(string $ipAddress, ?string $apiKey = null): string
    {
        if (@inet_pton($ipAddress) === false) {
            throw new \InvalidArgumentException('Cannot build provider endpoint for invalid IP address.');
        }

        $endpoint = str_replace('{ip}', rawurlencode($ipAddress), $this->endpointTemplate);
        if (str_contains($endpoint, '{api_key}')) {
            $endpoint = str_replace('{api_key}', rawurlencode((string) $apiKey), $endpoint);
        }

        return $endpoint;
    }

    public function supportsRegion(?string $region): bool
    {
        $region = $region === null || trim($region) === '' ? 'global' : strtoupper(trim($region));
        $regions = array_map(static fn (string $value): string => strtoupper($value), $this->regions);

        return in_array($region, $regions, true) || in_array('GLOBAL', $regions, true);
    }

    /**
     * @param array<mixed> $value
     * @return array<string, string>
     */
    private static function stringMap(array $value, string $fieldName): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || trim($key) === '' || !is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException('IP geo provider ' . $fieldName . ' must map strings to strings.');
            }
            $map[trim($key)] = trim($item);
        }

        return $map;
    }

    private static function isPlaintextSecretField(string $key): bool
    {
        if ($key === 'api_key_env_var') {
            return false;
        }

        $compact = preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? strtolower($key);

        return in_array($compact, ['apikey', 'key', 'token', 'authorization', 'secret'], true)
            || str_contains($compact, 'secret')
            || str_contains($compact, 'password');
    }
}
