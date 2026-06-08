<?php

declare(strict_types=1);

namespace VertoAD\Repository\Archive;

use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Domain\Archive\ColdQueryJob;

final class InMemoryArchiveRepository implements ArchiveRepositoryInterface
{
    /** @var list<ArchiveEvent> */
    private array $events;

    /** @var array<string, ArchiveManifest> */
    private array $manifests = [];

    /** @var array<string, ColdQueryJob> */
    private array $coldQueries = [];

    /**
     * @param list<ArchiveEvent> $events
     */
    public function __construct(array $events = [])
    {
        $this->events = $events;
    }

    public function pendingEvents(): array
    {
        return $this->events;
    }

    public function saveManifest(ArchiveManifest $manifest): ArchiveManifest
    {
        $this->manifests[$manifest->manifestId] = $manifest;

        return $manifest;
    }

    public function findManifest(string $manifestId): ?ArchiveManifest
    {
        return $this->manifests[$manifestId] ?? null;
    }

    public function manifests(): array
    {
        return array_values($this->manifests);
    }

    public function saveColdQuery(ColdQueryJob $job): ColdQueryJob
    {
        $this->coldQueries[$job->jobId] = $job;

        return $job;
    }

    public function findColdQuery(string $jobId): ?ColdQueryJob
    {
        return $this->coldQueries[$jobId] ?? null;
    }

    public function nextQueuedColdQuery(): ?ColdQueryJob
    {
        foreach ($this->coldQueries as $job) {
            if ($job->status === 'queued') {
                return $job;
            }
        }

        return null;
    }
}
