<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

final readonly class AdSlotSize
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
