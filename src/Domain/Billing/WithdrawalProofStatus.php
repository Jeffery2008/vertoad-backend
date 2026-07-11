<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

enum WithdrawalProofStatus: string
{
    case PendingUpload = 'pending_upload';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
