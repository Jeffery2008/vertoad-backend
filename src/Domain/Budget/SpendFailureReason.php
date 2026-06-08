<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

enum SpendFailureReason: string
{
    case TotalCap = 'total_cap';
    case DailyCap = 'daily_cap';
    case HourlyCap = 'hourly_cap';
    case InsufficientBalance = 'insufficient_balance';
    case DuplicateState = 'duplicate_state';
    case ExpiredReservation = 'expired_reservation';
}
