<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class ArchiveCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }
}
