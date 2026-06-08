<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

final readonly class TurnstileVerificationResult
{
    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        public bool $success,
        public string $code,
        public string $message,
        public bool $providerAvailable,
        public array $errorCodes = [],
    ) {
    }
}
