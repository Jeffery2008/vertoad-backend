<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

use InvalidArgumentException;

final readonly class AiReviewPolicy
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $baseUrl,
        public string $model,
        public string $prompt,
        public int $timeoutSeconds,
        public int $maxInputTokens,
        public int $maxOutputTokens,
        public float $temperature,
    ) {
        if ($this->provider !== 'openai_compatible') {
            throw new InvalidArgumentException('provider must be openai_compatible.');
        }

        if (!$this->isHttpUrl($this->baseUrl)) {
            throw new InvalidArgumentException('base_url must be an HTTP(S) URL.');
        }

        if (trim($this->model) === '') {
            throw new InvalidArgumentException('model must be a non-empty string.');
        }

        if (trim($this->prompt) === '') {
            throw new InvalidArgumentException('prompt must be a non-empty string.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('timeout_seconds must be at least 1.');
        }

        if ($this->maxInputTokens < 1) {
            throw new InvalidArgumentException('max_input_tokens must be at least 1.');
        }

        if ($this->maxOutputTokens < 1) {
            throw new InvalidArgumentException('max_output_tokens must be at least 1.');
        }

        if ($this->temperature < 0.0 || $this->temperature > 2.0) {
            throw new InvalidArgumentException('temperature must be between 0 and 2.');
        }
    }

    public static function disabledFallback(): self
    {
        return new self(
            enabled: false,
            provider: 'openai_compatible',
            baseUrl: 'https://localhost.invalid/v1',
            model: 'deterministic-v1',
            prompt: 'Deterministic local AI review fallback.',
            timeoutSeconds: 60,
            maxInputTokens: 12000,
            maxOutputTokens: 2000,
            temperature: 0.2,
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $allowedKeys = [
            'enabled' => true,
            'provider' => true,
            'base_url' => true,
            'model' => true,
            'prompt' => true,
            'timeout_seconds' => true,
            'max_input_tokens' => true,
            'max_output_tokens' => true,
            'temperature' => true,
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
            provider: self::requiredString($value, 'provider'),
            baseUrl: rtrim(self::requiredString($value, 'base_url'), '/'),
            model: self::requiredString($value, 'model'),
            prompt: self::requiredString($value, 'prompt'),
            timeoutSeconds: self::requiredInt($value, 'timeout_seconds'),
            maxInputTokens: self::requiredInt($value, 'max_input_tokens'),
            maxOutputTokens: self::requiredInt($value, 'max_output_tokens'),
            temperature: self::requiredNumber($value, 'temperature'),
        );
    }

    /** @return array<string, mixed> */
    public function toProviderConfig(string $apiKey): array
    {
        return [
            'base_url' => $this->baseUrl,
            'api_key' => $apiKey,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'timeout_seconds' => $this->timeoutSeconds,
            'max_input_tokens' => $this->maxInputTokens,
            'max_output_tokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function requiredString(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (!is_string($item) || trim($item) === '') {
            throw new InvalidArgumentException($key . ' must be a non-empty string.');
        }

        return trim($item);
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
     */
    private static function requiredNumber(array $value, string $key): float
    {
        $item = $value[$key] ?? null;
        if (!is_int($item) && !is_float($item)) {
            throw new InvalidArgumentException($key . ' must be a number.');
        }

        return (float) $item;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && is_string($host)
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
