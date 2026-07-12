<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use RuntimeException;

final class CpmBillingUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
