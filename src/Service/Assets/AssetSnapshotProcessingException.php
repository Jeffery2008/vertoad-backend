<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use RuntimeException;

final class AssetSnapshotProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
