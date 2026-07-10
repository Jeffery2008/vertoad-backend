<?php

declare(strict_types=1);

namespace VertoAD\Install;

final class InstallHttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
