<?php

declare(strict_types=1);

namespace VertoAD\Domain\Ledger;

use RuntimeException;

final class InsufficientLedgerBalanceException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('insufficient_ledger_balance');
    }
}
