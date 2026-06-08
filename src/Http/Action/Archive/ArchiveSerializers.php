<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Archive;

use VertoAD\Domain\Archive\ArchiveManifest;
use VertoAD\Domain\Archive\ColdQueryJob;

final readonly class ArchiveSerializers
{
    /**
     * @return array<string, mixed>
     */
    public static function manifest(ArchiveManifest $manifest): array
    {
        return [
            'manifest_id' => $manifest->manifestId,
            'status' => $manifest->status,
            'format' => $manifest->format,
            'event_count' => $manifest->eventCount,
            'partitions' => $manifest->partitions,
            'created_at' => $manifest->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function coldQuery(ColdQueryJob $job): array
    {
        return [
            'job_id' => $job->jobId,
            'status' => $job->status,
            'sql' => $job->sql,
            'parameters' => $job->parameters,
            'requested_by' => $job->requestedBy,
            'result_format' => $job->resultFormat,
            'row_count' => $job->rowCount,
            'result_object_key' => $job->resultObjectKey,
            'scanned_object_keys' => $job->scannedObjectKeys,
            'created_at' => $job->createdAt->format(DATE_ATOM),
            'completed_at' => $job->completedAt?->format(DATE_ATOM),
        ];
    }
}
