<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

interface ArchiveCommandRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult;
}
