<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    public function __construct(public int $limit, public int $windowSeconds)
    {
        if ($this->limit < 1) {
            throw new InvalidArgumentException('Rate limit must be at least 1.');
        }

        if ($this->windowSeconds < 1) {
            throw new InvalidArgumentException('Rate limit window must be at least 1 second.');
        }
    }
}
