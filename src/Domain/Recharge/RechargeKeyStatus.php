<?php

declare(strict_types=1);

namespace VertoAD\Domain\Recharge;

enum RechargeKeyStatus: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
