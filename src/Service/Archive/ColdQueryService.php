<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Archive\ColdQueryJob;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;

final readonly class ColdQueryService
{
    public function __construct(
        private ArchiveRepositoryInterface $repository,
        private string $resultBaseObjectKey,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(array $payload): ColdQueryJob
    {
        $sql = $this->requiredString($payload, 'sql');
        $requestedBy = $this->requiredString($payload, 'requested_by');
        $parameters = $payload['parameters'] ?? [];
        if (!is_array($parameters)) {
            throw new InvalidArgumentException('parameters must be an array.');
        }

        return $this->repository->saveColdQuery(new ColdQueryJob(
            jobId: 'cold_query_' . sha1($sql . '|' . json_encode($parameters, JSON_THROW_ON_ERROR) . '|' . $requestedBy . '|' . microtime(true)),
            status: 'queued',
            sql: $sql,
            parameters: array_values($parameters),
            requestedBy: $requestedBy,
            resultFormat: 'json',
            rowCount: 0,
            resultObjectKey: null,
            scannedObjectKeys: [],
            createdAt: new DateTimeImmutable(),
            completedAt: null,
        ));
    }

    public function find(string $jobId): ?ColdQueryJob
    {
        return $this->repository->findColdQuery($jobId);
    }

    public function runNext(): ?ColdQueryJob
    {
        $queued = $this->repository->nextQueuedColdQuery();
        if ($queued === null) {
            return null;
        }

        $scanned = [];
        foreach ($this->repository->manifests() as $manifest) {
            foreach ($manifest->partitions as $partition) {
                $scanned[] = $partition['object_key'];
            }
        }

        return $this->repository->saveColdQuery(new ColdQueryJob(
            jobId: $queued->jobId,
            status: 'completed',
            sql: $queued->sql,
            parameters: $queued->parameters,
            requestedBy: $queued->requestedBy,
            resultFormat: 'json',
            rowCount: count($scanned),
            resultObjectKey: rtrim($this->resultBaseObjectKey, '/') . '/' . $queued->jobId . '.json',
            scannedObjectKeys: $scanned,
            createdAt: $queued->createdAt,
            completedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        if (!isset($payload[$field]) || !is_scalar($payload[$field]) || trim((string) $payload[$field]) === '') {
            throw new InvalidArgumentException($field . ' must be a non-empty string.');
        }

        return trim((string) $payload[$field]);
    }
}
