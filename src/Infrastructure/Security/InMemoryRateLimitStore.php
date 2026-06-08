<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function increment(string $key, int $windowStartsAt, int $windowSeconds): int
    {
        $storeKey = $key . ':' . $windowStartsAt;
        $this->counts[$storeKey] = ($this->counts[$storeKey] ?? 0) + 1;

        return $this->counts[$storeKey];
    }
}
