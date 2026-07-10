<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

interface MysqlBackupRunnerInterface
{
    public function dump(string $destinationPath): void;

    public function restore(string $sourcePath): void;
}
