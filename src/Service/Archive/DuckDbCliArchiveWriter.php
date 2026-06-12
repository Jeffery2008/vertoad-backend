<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use DateTimeZone;
use VertoAD\Domain\Archive\ArchiveEvent;

final readonly class DuckDbCliArchiveWriter implements ArchiveWriterInterface
{
    public function __construct(
        private ArchiveObjectStorageInterface $storage,
        private string $duckDbBinary,
        private string $tempDirectory,
        private ArchiveCommandRunnerInterface $runner = new ProcessArchiveCommandRunner(),
        private int $timeoutSeconds = 120,
    ) {
        if (trim($duckDbBinary) === '') {
            throw new \InvalidArgumentException('DuckDB binary path is required.');
        }

        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('DuckDB archive timeout seconds must be positive.');
        }
    }

    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    public function writePartition(string $partition, string $objectKey, array $events): ArchivePartitionWriteManifest
    {
        if ($events === []) {
            throw new \InvalidArgumentException('Archive partition must contain at least one event.');
        }

        $workspace = $this->workspace();
        $jsonPath = $workspace . DIRECTORY_SEPARATOR . 'events.ndjson';
        $parquetPath = $workspace . DIRECTORY_SEPARATOR . 'partition.parquet';

        try {
            file_put_contents($jsonPath, $this->ndjson($partition, $events));
            $result = $this->runner->run(
                [$this->duckDbBinary, '-batch'],
                $this->exportSql($jsonPath, $parquetPath),
                $this->timeoutSeconds,
            );
            if ($result->exitCode !== 0 || !is_file($parquetPath)) {
                throw new \RuntimeException('DuckDB Parquet export failed: ' . trim($result->stderr . "\n" . $result->stdout));
            }

            $body = (string) file_get_contents($parquetPath);
            $this->storage->put($objectKey, $body, 'application/vnd.apache.parquet');

            return new ArchivePartitionWriteManifest(
                objectKey: $objectKey,
                checksum: 'sha256:' . hash('sha256', $body),
                byteCount: strlen($body),
                rowCount: count($events),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function workspace(): string
    {
        $base = rtrim($this->tempDirectory, DIRECTORY_SEPARATOR);
        if ($base === '') {
            throw new \InvalidArgumentException('DuckDB archive temp directory is required.');
        }

        if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
            throw new \RuntimeException('Unable to create DuckDB archive temp directory.');
        }

        $workspace = $base . DIRECTORY_SEPARATOR . 'archive-writer-' . bin2hex(random_bytes(8));
        if (!mkdir($workspace, 0777, true) && !is_dir($workspace)) {
            throw new \RuntimeException('Unable to create DuckDB archive workspace.');
        }

        return $workspace;
    }

    /**
     * @param non-empty-list<ArchiveEvent> $events
     */
    private function ndjson(string $partition, array $events): string
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = json_encode([
                'partition' => $partition,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                'date' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
                'hour' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('H'),
                'payload' => $event->payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $rows) . "\n";
    }

    private function exportSql(string $jsonPath, string $parquetPath): string
    {
        return sprintf(
            "COPY (SELECT * FROM read_json_auto('%s', format='newline_delimited')) TO '%s' (FORMAT PARQUET, COMPRESSION ZSTD);\n",
            $this->sqlLiteral($jsonPath),
            $this->sqlLiteral($parquetPath),
        );
    }

    private function sqlLiteral(string $value): string
    {
        return str_replace("'", "''", str_replace('\\', '/', $value));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
