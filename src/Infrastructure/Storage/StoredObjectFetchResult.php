<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

final readonly class StoredObjectFetchResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }
}
