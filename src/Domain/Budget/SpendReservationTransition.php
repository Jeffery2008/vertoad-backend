<?php

declare(strict_types=1);

namespace VertoAD\Domain\Budget;

final readonly class SpendReservationTransition
{
    public function __construct(
        public bool $changed,
        public ?SpendReservation $reservation,
    ) {
    }
}
