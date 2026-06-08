<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

final readonly class SpendReservationResult
{
    private function __construct(
        public bool $accepted,
        public ?SpendReservation $reservation,
        public ?SpendFailureReason $failureReason,
    ) {
    }

    public static function accepted(SpendReservation $reservation): self
    {
        return new self(true, $reservation, null);
    }

    public static function rejected(SpendFailureReason $reason, ?SpendReservation $reservation = null): self
    {
        return new self(false, $reservation, $reason);
    }
}
