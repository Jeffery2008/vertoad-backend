<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use RuntimeException;

final class WithdrawalProofValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
