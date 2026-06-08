<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

interface RateLimitStoreInterface
{
    public function increment(string $key, int $windowStartsAt, int $windowSeconds): int;
}
