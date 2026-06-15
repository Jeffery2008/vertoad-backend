<?php

declare(strict_types=1);

namespace VertoAD\Repository\Archive;

use Doctrine\DBAL\ArrayParameterType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Domain\Archive\ColdQueryJob;

final readonly class DatabaseArchiveRepository implements ArchiveRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function pendingEvents(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('event_uuid', 'event_type', 'occurred_at', 'payload_json')
            ->from('raw_events')
            ->where('processed_at IS NULL')
            ->orderBy('occurred_at', 'ASC')
            ->addOrderBy('event_uuid', 'ASC')
            ->fetchAllAssociative();

        $uniqueRows = [];
        foreach ($rows as $row) {
            $eventUuid = (string) $row['event_uuid'];
            if (!array_key_exists($eventUuid, $uniqueRows)) {
                $uniqueRows[$eventUuid] = $row;
            }
        }

        return array_map(fn (array $row): ArchiveEvent => $this->eventFromRow($row), array_values($uniqueRows));
    }

    public function saveManifest(ArchiveManifest $manifest): ArchiveManifest
    {
        $row = [
            'manifest_id' => $manifest->manifestId,
            'status' => $manifest->status,
            'format' => $manifest->format,
            'event_count' => $manifest->eventCount,
            'partitions_json' => json_encode($manifest->partitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $this->formatDate($manifest->createdAt),
        ];
        if ($this->findManifest($manifest->manifestId) === null) {
            $this->connection->insert('archive_manifests', $row);

            return $manifest;
        }

        $this->connection->update('archive_manifests', $row, ['manifest_id' => $manifest->manifestId]);

        return $manifest;
    }

    public function markEventsArchived(array $eventIds, DateTimeImmutable $processedAt): void
    {
        if ($eventIds === []) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE raw_events SET processed_at = :processed_at WHERE processed_at IS NULL AND event_uuid IN (:event_ids)',
            [
                'processed_at' => $this->formatDate($processedAt),
                'event_ids' => array_values(array_unique($eventIds)),
            ],
            [
                'event_ids' => ArrayParameterType::STRING,
            ],
        );
    }

    public function findManifest(string $manifestId): ?ArchiveManifest
    {
        $row = $this->connection->createQueryBuilder()
            ->select('manifest_id', 'status', 'format', 'event_count', 'partitions_json', 'created_at')
            ->from('archive_manifests')
            ->where('manifest_id = :manifest_id')
            ->setParameter('manifest_id', trim($manifestId))
            ->fetchAssociative();

        return $row === false ? null : $this->manifestFromRow($row);
    }

    public function manifests(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('manifest_id', 'status', 'format', 'event_count', 'partitions_json', 'created_at')
            ->from('archive_manifests')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('manifest_id', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): ArchiveManifest => $this->manifestFromRow($row), $rows);
    }

    public function saveColdQuery(ColdQueryJob $job): ColdQueryJob
    {
        $row = [
            'job_id' => $job->jobId,
            'status' => $job->status,
            'sql_text' => $job->sql,
            'parameters_json' => json_encode($job->parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'requested_by' => $job->requestedBy,
            'result_format' => $job->resultFormat,
            'row_count' => $job->rowCount,
            'result_object_key' => $job->resultObjectKey,
            'scanned_object_keys_json' => json_encode($job->scannedObjectKeys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'error_message' => $job->errorMessage,
            'created_at' => $this->formatDate($job->createdAt),
            'completed_at' => $job->completedAt === null ? null : $this->formatDate($job->completedAt),
        ];
        if ($this->findColdQuery($job->jobId) === null) {
            $this->connection->insert('archive_cold_query_jobs', $row);

            return $job;
        }

        $this->connection->update('archive_cold_query_jobs', $row, ['job_id' => $job->jobId]);

        return $job;
    }

    public function findColdQuery(string $jobId): ?ColdQueryJob
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->coldQueryColumns())
            ->from('archive_cold_query_jobs')
            ->where('job_id = :job_id')
            ->setParameter('job_id', trim($jobId))
            ->fetchAssociative();

        return $row === false ? null : $this->coldQueryFromRow($row);
    }

    public function nextQueuedColdQuery(): ?ColdQueryJob
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->coldQueryColumns())
            ->from('archive_cold_query_jobs')
            ->where('status = :status')
            ->setParameter('status', 'queued')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('job_id', 'ASC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row === false ? null : $this->coldQueryFromRow($row);
    }

    /** @return list<string> */
    private function coldQueryColumns(): array
    {
        return ['job_id', 'status', 'sql_text', 'parameters_json', 'requested_by', 'result_format', 'row_count', 'result_object_key', 'scanned_object_keys_json', 'error_message', 'created_at', 'completed_at'];
    }

    /** @param array<string, mixed> $row */
    private function eventFromRow(array $row): ArchiveEvent
    {
        $payload = json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR);

        return new ArchiveEvent(
            eventType: (string) $row['event_type'],
            eventId: (string) $row['event_uuid'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
            payload: is_array($payload) ? $payload : [],
        );
    }

    /** @param array<string, mixed> $row */
    private function manifestFromRow(array $row): ArchiveManifest
    {
        $partitions = json_decode((string) $row['partitions_json'], true, flags: JSON_THROW_ON_ERROR);

        return new ArchiveManifest(
            manifestId: (string) $row['manifest_id'],
            status: (string) $row['status'],
            format: (string) $row['format'],
            eventCount: (int) $row['event_count'],
            partitions: is_array($partitions) ? array_values($partitions) : [],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed> $row */
    private function coldQueryFromRow(array $row): ColdQueryJob
    {
        $parameters = json_decode((string) $row['parameters_json'], true, flags: JSON_THROW_ON_ERROR);
        $scanned = json_decode((string) $row['scanned_object_keys_json'], true, flags: JSON_THROW_ON_ERROR);

        return new ColdQueryJob(
            jobId: (string) $row['job_id'],
            status: (string) $row['status'],
            sql: (string) $row['sql_text'],
            parameters: is_array($parameters) ? array_values($parameters) : [],
            requestedBy: (string) $row['requested_by'],
            resultFormat: (string) $row['result_format'],
            rowCount: (int) $row['row_count'],
            resultObjectKey: $row['result_object_key'] === null ? null : (string) $row['result_object_key'],
            scannedObjectKeys: is_array($scanned) ? array_values(array_map('strval', $scanned)) : [],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            completedAt: $row['completed_at'] === null ? null : new DateTimeImmutable((string) $row['completed_at'], new DateTimeZone('UTC')),
            errorMessage: $row['error_message'] === null ? null : (string) $row['error_message'],
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
