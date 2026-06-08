<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

final readonly class AdEventResult
{
    private function __construct(
        public bool $accepted,
        public bool $duplicate,
        public ?string $reason,
        public ?string $redirectUrl = null,
    ) {
    }

    public static function accepted(bool $duplicate = false, ?string $redirectUrl = null): self
    {
        return new self(true, $duplicate, null, $redirectUrl);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, false, $reason);
    }
}
