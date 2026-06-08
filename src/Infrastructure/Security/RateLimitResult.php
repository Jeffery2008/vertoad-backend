<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfterSeconds,
        public int $resetAt,
    ) {
    }
}
