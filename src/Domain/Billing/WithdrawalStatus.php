<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
