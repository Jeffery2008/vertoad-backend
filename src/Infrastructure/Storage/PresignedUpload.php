<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

final readonly class PresignedUpload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $url,
        public string $method,
        public string $objectKey,
        public int $statusCode,
        public array $headers,
    ) {
    }
}
