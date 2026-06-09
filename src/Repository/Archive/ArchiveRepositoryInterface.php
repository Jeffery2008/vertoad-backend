<?php

declare(strict_types=1);

namespace VertoAD\Repository\Archive;

use DateTimeImmutable;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Domain\Archive\ColdQueryJob;

interface ArchiveRepositoryInterface
{
    /**
     * @return list<ArchiveEvent>
     */
    public function pendingEvents(): array;

    public function saveManifest(ArchiveManifest $manifest): ArchiveManifest;

    /**
     * @param list<string> $eventIds
     */
    public function markEventsArchived(array $eventIds, DateTimeImmutable $processedAt): void;

    public function findManifest(string $manifestId): ?ArchiveManifest;

    /**
     * @return list<ArchiveManifest>
     */
    public function manifests(): array;

    public function saveColdQuery(ColdQueryJob $job): ColdQueryJob;

    public function findColdQuery(string $jobId): ?ColdQueryJob;

    public function nextQueuedColdQuery(): ?ColdQueryJob;
}
