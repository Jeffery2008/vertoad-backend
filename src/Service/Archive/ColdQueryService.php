<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use VertoAD\Domain\Archive\ColdQueryJob;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;

final readonly class ColdQueryService
{
    public function __construct(
        private ArchiveRepositoryInterface $repository,
        private string $resultBaseObjectKey,
        private ColdQueryRunnerInterface $runner,
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
            errorMessage: null,
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

        $scanned = $this->scannableObjectKeys();
        $resultObjectKey = rtrim($this->resultBaseObjectKey, '/') . '/' . $queued->jobId . '.json';
        $running = $this->repository->saveColdQuery(new ColdQueryJob(
            jobId: $queued->jobId,
            status: 'running',
            sql: $queued->sql,
            parameters: $queued->parameters,
            requestedBy: $queued->requestedBy,
            resultFormat: $queued->resultFormat,
            rowCount: 0,
            resultObjectKey: null,
            scannedObjectKeys: $scanned,
            createdAt: $queued->createdAt,
            completedAt: null,
            errorMessage: null,
        ));

        try {
            $result = $this->runner->run(new ColdQueryExecutionRequest(
                jobId: $running->jobId,
                sql: $running->sql,
                parameters: $running->parameters,
                objectKeys: $scanned,
                resultObjectKey: $resultObjectKey,
                currentStatus: $running->status,
            ));
        } catch (Throwable $exception) {
            return $this->repository->saveColdQuery(new ColdQueryJob(
                jobId: $running->jobId,
                status: 'failed',
                sql: $running->sql,
                parameters: $running->parameters,
                requestedBy: $running->requestedBy,
                resultFormat: $running->resultFormat,
                rowCount: 0,
                resultObjectKey: null,
                scannedObjectKeys: $scanned,
                createdAt: $running->createdAt,
                completedAt: new DateTimeImmutable(),
                errorMessage: $exception->getMessage(),
            ));
        }

        return $this->repository->saveColdQuery(new ColdQueryJob(
            jobId: $running->jobId,
            status: 'completed',
            sql: $running->sql,
            parameters: $running->parameters,
            requestedBy: $running->requestedBy,
            resultFormat: $result->resultFormat,
            rowCount: $result->rowCount,
            resultObjectKey: $result->resultObjectKey,
            scannedObjectKeys: $result->scannedObjectKeys,
            createdAt: $running->createdAt,
            completedAt: new DateTimeImmutable(),
            errorMessage: null,
        ));
    }

    /**
     * @return list<string>
     */
    private function scannableObjectKeys(): array
    {
        $scanned = [];
        foreach ($this->repository->manifests() as $manifest) {
            if ($manifest->status !== 'completed') {
                continue;
            }

            foreach ($manifest->partitions as $partition) {
                if (isset($partition['object_key']) && is_scalar($partition['object_key'])) {
                    $scanned[] = (string) $partition['object_key'];
                }
            }
        }

        return array_values(array_unique($scanned));
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
