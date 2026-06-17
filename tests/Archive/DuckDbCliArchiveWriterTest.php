<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;
use VertoAD\Service\Archive\ArchiveObjectStorageInterface;
use VertoAD\Service\Archive\DuckDbCliArchiveWriter;

final class DuckDbCliArchiveWriterTest extends TestCase
{
    public function testWritesRealParquetWithDuckDbAndUploadsPartitionObject(): void
    {
        $storage = new RecordingArchiveObjectStorage();
        $runner = new ParquetWritingRunner("PAR1fake-parquet-bytes");
        $tempDirectory = $this->temporaryDirectory();
        $writer = new DuckDbCliArchiveWriter($storage, 'duckdb-test', $tempDirectory, $runner);

        $manifest = $writer->writePartition(
            'event_type=click/date=2026-06-08/hour=10',
            's3://archive/raw/event_type=click/date=2026-06-08/hour=10/part-1.parquet',
            [
                new ArchiveEvent('click', 'click:clk-1', new DateTimeImmutable('2026-06-08T10:01:00Z'), [
                    'campaign_id' => 123,
                    'viewer_id' => 'viewer-1',
                ]),
                new ArchiveEvent('click', 'click:clk-2', new DateTimeImmutable('2026-06-08T10:02:00Z'), [
                    'campaign_id' => 124,
                    'valid' => true,
                ]),
            ],
        );

        self::assertSame('s3://archive/raw/event_type=click/date=2026-06-08/hour=10/part-1.parquet', $manifest->objectKey);
        self::assertSame('sha256:' . hash('sha256', "PAR1fake-parquet-bytes"), $manifest->checksum);
        self::assertSame(strlen("PAR1fake-parquet-bytes"), $manifest->byteCount);
        self::assertSame(2, $manifest->rowCount);
        self::assertSame('application/vnd.apache.parquet', $storage->puts[0]['content_type'] ?? null);
        self::assertSame("PAR1fake-parquet-bytes", $storage->puts[0]['body'] ?? null);
        self::assertSame(['duckdb-test', '-batch'], $runner->commands[0]);
        self::assertStringContainsString('read_json_auto', $runner->stdins[0]);
        self::assertStringContainsString('COPY (', $runner->stdins[0]);
        self::assertStringContainsString('FORMAT PARQUET', $runner->stdins[0]);
        self::assertStringContainsString('COMPRESSION ZSTD', $runner->stdins[0]);
        self::assertSame([], glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testRunnerFailureDeletesTemporaryFilesAndDoesNotUpload(): void
    {
        $storage = new RecordingArchiveObjectStorage();
        $runner = new NestedWorkspaceFailingArchiveCommandRunner();
        $tempDirectory = $this->temporaryDirectory();
        $writer = new DuckDbCliArchiveWriter($storage, 'duckdb-test', $tempDirectory, $runner);

        try {
            $writer->writePartition(
                'event_type=impression/date=2026-06-08/hour=10',
                's3://archive/raw/part-failed.parquet',
                [new ArchiveEvent('impression', 'imp-1', new DateTimeImmutable('2026-06-08T10:01:00Z'), [])],
            );
            self::fail('Expected DuckDB writer failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('DuckDB Parquet export failed: simulated duckdb failure', $exception->getMessage());
        }

        self::assertSame([], $storage->puts);
        self::assertSame([], glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testRejectsEmptyPartitionsBeforeStartingDuckDb(): void
    {
        $runner = new ParquetWritingRunner('PAR1');
        $writer = new DuckDbCliArchiveWriter(new RecordingArchiveObjectStorage(), 'duckdb-test', $this->temporaryDirectory(), $runner);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive partition must contain at least one event.');

        $writer->writePartition('event_type=click/date=2026-06-08/hour=10', 's3://archive/raw/empty.parquet', []);
    }

    public function testRejectsBlankTempDirectoryBeforeCreatingWorkspace(): void
    {
        $writer = new DuckDbCliArchiveWriter(
            new RecordingArchiveObjectStorage(),
            'duckdb-test',
            '',
            new ParquetWritingRunner('PAR1'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DuckDB archive temp directory is required.');

        $writer->writePartition(
            'event_type=click/date=2026-06-08/hour=10',
            's3://archive/raw/blank-temp.parquet',
            [new ArchiveEvent('click', 'clk-blank-temp', new DateTimeImmutable('2026-06-08T10:00:00Z'), [])],
        );
    }

    public function testReportsTempDirectoryCreationFailure(): void
    {
        $scheme = 'vertoadwriterbase';
        ArchiveWriterDirectoryStreamWrapper::register($scheme, []);
        $writer = new DuckDbCliArchiveWriter(
            new RecordingArchiveObjectStorage(),
            'duckdb-test',
            $scheme . '://base',
            new ParquetWritingRunner('PAR1'),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create DuckDB archive temp directory.');

            $writer->writePartition(
                'event_type=click/date=2026-06-08/hour=10',
                's3://archive/raw/base-failure.parquet',
                [new ArchiveEvent('click', 'clk-base-failure', new DateTimeImmutable('2026-06-08T10:00:00Z'), [])],
            );
        } finally {
            ArchiveWriterDirectoryStreamWrapper::unregister($scheme);
        }
    }

    public function testReportsWorkspaceCreationFailure(): void
    {
        $scheme = 'vertoadwriterworkspace';
        ArchiveWriterDirectoryStreamWrapper::register($scheme, [$scheme . '://base']);
        $writer = new DuckDbCliArchiveWriter(
            new RecordingArchiveObjectStorage(),
            'duckdb-test',
            $scheme . '://base',
            new ParquetWritingRunner('PAR1'),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create DuckDB archive workspace.');

            $writer->writePartition(
                'event_type=click/date=2026-06-08/hour=10',
                's3://archive/raw/workspace-failure.parquet',
                [new ArchiveEvent('click', 'clk-workspace-failure', new DateTimeImmutable('2026-06-08T10:00:00Z'), [])],
            );
        } finally {
            ArchiveWriterDirectoryStreamWrapper::unregister($scheme);
        }
    }

    public function testCleanupIgnoresWorkspaceAlreadyRemovedByDuckDbFailure(): void
    {
        $writer = new DuckDbCliArchiveWriter(
            new RecordingArchiveObjectStorage(),
            'duckdb-test',
            $this->temporaryDirectory(),
            new WorkspaceDeletingArchiveCommandRunner(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DuckDB Parquet export failed: workspace deleted');

        $writer->writePartition(
            'event_type=click/date=2026-06-08/hour=10',
            's3://archive/raw/deleted-workspace.parquet',
            [new ArchiveEvent('click', 'clk-deleted-workspace', new DateTimeImmutable('2026-06-08T10:00:00Z'), [])],
        );
    }

    public function testRejectsInvalidConstructorSettingsAndCreatesMissingTempDirectory(): void
    {
        try {
            new DuckDbCliArchiveWriter(new RecordingArchiveObjectStorage(), '', $this->temporaryDirectory());
            self::fail('Expected missing binary validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB binary path is required.', $exception->getMessage());
        }

        try {
            new DuckDbCliArchiveWriter(new RecordingArchiveObjectStorage(), 'duckdb-test', $this->temporaryDirectory(), timeoutSeconds: 0);
            self::fail('Expected timeout validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB archive timeout seconds must be positive.', $exception->getMessage());
        }

        $missingTempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-duckdb-writer-missing-' . bin2hex(random_bytes(4));
        $storage = new RecordingArchiveObjectStorage();
        $writer = new DuckDbCliArchiveWriter(
            $storage,
            'duckdb-test',
            $missingTempDirectory,
            new ParquetWritingRunner('PAR1created'),
        );

        $writer->writePartition(
            'event_type=click/date=2026-06-08/hour=10',
            's3://archive/raw/created.parquet',
            [new ArchiveEvent('click', 'clk-created', new DateTimeImmutable('2026-06-08T10:00:00Z'), [])],
        );

        self::assertDirectoryExists($missingTempDirectory);
        self::assertSame('PAR1created', $storage->puts[0]['body'] ?? null);
        @rmdir($missingTempDirectory);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-duckdb-writer-' . bin2hex(random_bytes(4));
        mkdir($path);

        return $path;
    }
}

final class RecordingArchiveObjectStorage implements ArchiveObjectStorageInterface
{
    /** @var list<array{object_key:string, body:string, content_type:string}> */
    public array $puts = [];

    /** @param array<string, string> $objects */
    public function __construct(private array $objects = [])
    {
    }

    public function put(string $objectKey, string $body, string $contentType): void
    {
        $this->puts[] = ['object_key' => $objectKey, 'body' => $body, 'content_type' => $contentType];
        $this->objects[$objectKey] = $body;
    }

    public function get(string $objectKey): string
    {
        if (!array_key_exists($objectKey, $this->objects)) {
            throw new \RuntimeException('missing object ' . $objectKey);
        }

        return $this->objects[$objectKey];
    }
}

final class ParquetWritingRunner implements ArchiveCommandRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<string> */
    public array $stdins = [];

    public function __construct(private readonly string $parquetBytes)
    {
    }

    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        $this->commands[] = $command;
        $this->stdins[] = $stdin ?? '';
        if (preg_match("/TO '([^']+)'/i", $stdin ?? '', $matches) !== 1) {
            return new ArchiveCommandResult(1, '', 'missing output path');
        }

        file_put_contents(str_replace("''", "'", $matches[1]), $this->parquetBytes);

        return new ArchiveCommandResult(0, 'ok', '');
    }
}

final class FailingArchiveCommandRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        return new ArchiveCommandResult(2, '', 'simulated duckdb failure');
    }
}

final class NestedWorkspaceFailingArchiveCommandRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        if (preg_match("/read_json_auto\\('([^']+)'/i", $stdin ?? '', $matches) === 1) {
            $workspace = dirname(str_replace("''", "'", $matches[1]));
            mkdir($workspace . DIRECTORY_SEPARATOR . 'nested-artifacts');
            file_put_contents($workspace . DIRECTORY_SEPARATOR . 'nested-artifacts' . DIRECTORY_SEPARATOR . 'debug.tmp', 'debug');
        }

        return new ArchiveCommandResult(2, '', 'simulated duckdb failure');
    }
}

final class WorkspaceDeletingArchiveCommandRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        if (preg_match("/read_json_auto\\('([^']+)'/i", $stdin ?? '', $matches) === 1) {
            $workspace = dirname(str_replace("''", "'", $matches[1]));
            @unlink($workspace . DIRECTORY_SEPARATOR . 'events.ndjson');
            @rmdir($workspace);
        }

        return new ArchiveCommandResult(2, '', 'workspace deleted');
    }
}

final class ArchiveWriterDirectoryStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @var array<string, true> */
    private static array $existingDirectories = [];

    /**
     * @param list<string> $existingDirectories
     */
    public static function register(string $scheme, array $existingDirectories): void
    {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }

        self::$existingDirectories = array_fill_keys($existingDirectories, true);
        stream_wrapper_register($scheme, self::class);
    }

    public static function unregister(string $scheme): void
    {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }

        self::$existingDirectories = [];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!isset(self::$existingDirectories[$path])) {
            return false;
        }

        return ['mode' => 0040777];
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return false;
    }
}
