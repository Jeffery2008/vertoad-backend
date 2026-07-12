<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

final readonly class CpmBillingClaim
{
    public function __construct(
        public CpmBillingAllocation $allocation,
        public bool $acquired,
    ) {
    }
}
