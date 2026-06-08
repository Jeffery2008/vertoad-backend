<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

final readonly class AdEventBillingResult
{
    private function __construct(
        public bool $billed,
        public bool $duplicate,
        public ?string $reason,
        public int $grossPoints,
        public int $publisherPoints,
    ) {
    }

    public static function billed(int $grossPoints, int $publisherPoints, bool $duplicate = false): self
    {
        return new self(true, $duplicate, null, $grossPoints, $publisherPoints);
    }

    public static function skipped(string $reason): self
    {
        return new self(false, false, $reason, 0, 0);
    }
}
