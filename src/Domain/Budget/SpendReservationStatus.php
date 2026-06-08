<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

enum SpendReservationStatus: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';
}
