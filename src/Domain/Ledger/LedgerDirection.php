<?php

declare(strict_types=1);

namespace VertoAD\Domain\Ledger;

enum LedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function reverse(): self
    {
        return match ($this) {
            self::Credit => self::Debit,
            self::Debit => self::Credit,
        };
    }
}
