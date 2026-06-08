<?php

declare(strict_types=1);

namespace VertoAD\Domain\Operations;

use DateTimeImmutable;

final readonly class OperationErrorLog
{
    /**
     * @param array<string, mixed> $redactedContext
     * @param array<string, mixed>|null $rawContext
     */
    public function __construct(
        public string $error_id,
        public string $request_id,
        public string $severity,
        public string $message,
        public array $redacted_context,
        public ?array $raw_context,
        public string $source,
        public DateTimeImmutable $occurred_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRawContext = false): array
    {
        return [
            'error_id' => $this->error_id,
            'request_id' => $this->request_id,
            'severity' => $this->severity,
            'message' => $this->message,
            'redacted_context' => $this->redacted_context,
            'raw_context' => $includeRawContext ? $this->raw_context : null,
            'source' => $this->source,
            'occurred_at' => $this->occurred_at->format(DATE_ATOM),
        ];
    }
}
