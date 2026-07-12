<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

enum CpmBillingAllocationStatus: string
{
    case Processing = 'processing';
    case Accrued = 'accrued';
    case Billed = 'billed';
    case Skipped = 'skipped';
}
