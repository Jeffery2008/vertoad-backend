<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use RuntimeException;

final readonly class UnavailableMysqlBackupRunner implements MysqlBackupRunnerInterface
{
    public function __construct(private string $message = 'Backup MySQL runner is not configured.')
    {
    }

    public function dump(string $destinationPath): void
    {
        throw new RuntimeException($this->message);
    }

    public function restore(string $sourcePath): void
    {
        throw new RuntimeException($this->message);
    }
}
