<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

final readonly class CpmBillingAllocationCalculation
{
    public function __construct(
        public CpmBillingAccumulator $nextAccumulator,
        public int $bidPointsPerThousand,
        public int $grossRemainderBefore,
        public int $grossPoints,
        public int $grossRemainderAfter,
        public int $publisherShareRemainderBefore,
        public int $publisherPoints,
        public int $publisherShareRemainderAfter,
        public int $platformPoints,
    ) {
    }
}
