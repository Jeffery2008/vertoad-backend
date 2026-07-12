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

    public static function accrued(bool $duplicate = false): self
    {
        return new self(true, $duplicate, 'cpm_fraction_accumulated', 0, 0);
    }

    public static function skipped(string $reason, bool $duplicate = false): self
    {
        return new self(false, $duplicate, $reason, 0, 0);
    }
}
