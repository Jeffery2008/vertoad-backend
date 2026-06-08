<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;

final readonly class ArchiveService
{
    public function __construct(private ArchiveRepositoryInterface $repository)
    {
    }

    public function manifest(string $manifestId): ?ArchiveManifest
    {
        return $this->repository->findManifest($manifestId);
    }
}
