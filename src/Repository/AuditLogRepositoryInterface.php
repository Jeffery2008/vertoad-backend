<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Audit\AuditLogEntry;

interface AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void;
}
