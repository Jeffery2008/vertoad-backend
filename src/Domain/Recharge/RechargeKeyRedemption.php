<?php

declare(strict_types=1);

namespace VertoAD\Domain\Recharge;

use VertoAD\Domain\Ledger\PointsLedgerEntry;

final readonly class RechargeKeyRedemption
{
    public function __construct(
        public RechargeKey $key,
        public PointsLedgerEntry $ledgerEntry,
    ) {
    }
}
