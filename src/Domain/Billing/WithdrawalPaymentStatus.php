<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

enum WithdrawalPaymentStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Paid = 'paid';
}
