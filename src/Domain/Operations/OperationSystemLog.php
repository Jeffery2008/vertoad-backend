<?php

declare(strict_types=1);

namespace VertoAD\Domain\Operations;

use DateTimeImmutable;

final readonly class OperationSystemLog
{
    /**
     * @param array<string, mixed> $redacted_context
     * @param array<string, mixed>|null $raw_context
     */
    public function __construct(
        public string $log_id,
        public string $request_id,
        public string $level,
        public string $message,
        public ?string $endpoint,
        public ?string $http_method,
        public ?string $ip_address,
        public string $source,
        public array $redacted_context,
        public ?array $raw_context,
        public DateTimeImmutable $occurred_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRawContext = false): array
    {
        $payload = [
            'log_id' => $this->log_id,
            'request_id' => $this->request_id,
            'level' => $this->level,
            'message' => $this->message,
            'endpoint' => $this->endpoint,
            'http_method' => $this->http_method,
            'ip_address' => $this->ip_address,
            'source' => $this->source,
            'redacted_context' => $this->redacted_context,
            'occurred_at' => $this->occurred_at->format(DATE_ATOM),
        ];

        if ($includeRawContext) {
            $payload['raw_context'] = $this->raw_context;
        }

        return $payload;
    }
}
