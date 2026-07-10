<?php

declare(strict_types=1);

namespace VertoAD\Install;

interface MigrationRunnerInterface
{
    /** @param array<string, mixed> $databaseSettings */
    public function migrate(#[\SensitiveParameter] array $databaseSettings): void;
}
