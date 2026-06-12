<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

use InvalidArgumentException;

final readonly class ServingEventPolicy
{
    public function __construct(
        public float $minVisibleRatio,
        public int $minVisibleMs,
        public int $repeatClickWindowSeconds,
    ) {
        if ($this->minVisibleRatio < 0.0 || $this->minVisibleRatio > 1.0) {
            throw new InvalidArgumentException('Minimum visible ratio must be between 0 and 1.');
        }

        if ($this->minVisibleMs < 1) {
            throw new InvalidArgumentException('Minimum visible milliseconds must be at least 1.');
        }

        if ($this->repeatClickWindowSeconds < 1) {
            throw new InvalidArgumentException('Repeat click window must be at least 1 second.');
        }
    }
}
