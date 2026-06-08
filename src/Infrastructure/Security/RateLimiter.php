<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

use DateTimeImmutable;

final readonly class RateLimiter
{
    public function __construct(private RateLimitStoreInterface $store)
    {
    }

    public function hit(RateLimitDimensions $dimensions, RateLimitPolicy $policy, DateTimeImmutable $now): RateLimitResult
    {
        $timestamp = $now->getTimestamp();
        $windowStartsAt = intdiv($timestamp, $policy->windowSeconds) * $policy->windowSeconds;
        $count = $this->store->increment($dimensions->key(), $windowStartsAt, $policy->windowSeconds);
        $resetAt = $windowStartsAt + $policy->windowSeconds;
        $allowed = $count <= $policy->limit;
        $remaining = $allowed ? max(0, $policy->limit - $count) : $policy->limit;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $policy->limit,
            remaining: $remaining,
            retryAfterSeconds: max(1, $resetAt - $timestamp),
            resetAt: $resetAt,
        );
    }
}
