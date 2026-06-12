<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use InvalidArgumentException;

final readonly class WebhookDeliveryPolicy
{
    private const MAX_BATCH_SIZE = 500;
    private const MAX_HTTP_TIMEOUT_SECONDS = 60;
    private const MAX_RETRY_COUNT = 20;
    private const MAX_RETRY_BASE_BACKOFF_SECONDS = 86400;

    public function __construct(
        public int $batchSize,
        public int $httpTimeoutSeconds,
        public int $maxRetryCount,
        public int $retryBaseBackoffSeconds,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('batch_size must be between 1 and ' . self::MAX_BATCH_SIZE . '.');
        }

        if ($this->httpTimeoutSeconds < 1 || $this->httpTimeoutSeconds > self::MAX_HTTP_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(
                'http_timeout_seconds must be between 1 and ' . self::MAX_HTTP_TIMEOUT_SECONDS . '.',
            );
        }

        if ($this->maxRetryCount < 1 || $this->maxRetryCount > self::MAX_RETRY_COUNT) {
            throw new InvalidArgumentException('max_retry_count must be between 1 and ' . self::MAX_RETRY_COUNT . '.');
        }

        if ($this->retryBaseBackoffSeconds < 1 || $this->retryBaseBackoffSeconds > self::MAX_RETRY_BASE_BACKOFF_SECONDS) {
            throw new InvalidArgumentException(
                'retry_base_backoff_seconds must be between 1 and ' . self::MAX_RETRY_BASE_BACKOFF_SECONDS . '.',
            );
        }
    }

    public static function default(): self
    {
        return new self(
            batchSize: 50,
            httpTimeoutSeconds: 5,
            maxRetryCount: 3,
            retryBaseBackoffSeconds: 300,
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $allowedKeys = [
            'batch_size' => true,
            'http_timeout_seconds' => true,
            'max_retry_count' => true,
            'retry_base_backoff_seconds' => true,
        ];
        foreach ($value as $key => $_) {
            if (!isset($allowedKeys[(string) $key])) {
                throw new InvalidArgumentException('unknown field ' . (string) $key . '.');
            }
        }

        return new self(
            batchSize: self::requiredInt($value, 'batch_size'),
            httpTimeoutSeconds: self::requiredInt($value, 'http_timeout_seconds'),
            maxRetryCount: self::requiredInt($value, 'max_retry_count'),
            retryBaseBackoffSeconds: self::requiredInt($value, 'retry_base_backoff_seconds'),
        );
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
}
