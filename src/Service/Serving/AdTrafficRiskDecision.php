<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

final readonly class AdTrafficRiskDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
        if (!$allowed && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('Serving risk rejection reason is required.');
        }
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }
}
