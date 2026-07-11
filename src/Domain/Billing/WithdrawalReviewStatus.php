<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

enum WithdrawalReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
