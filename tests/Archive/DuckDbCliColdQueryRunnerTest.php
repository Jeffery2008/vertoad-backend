<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;
use VertoAD\Service\Archive\ColdQueryExecutionRequest;
use VertoAD\Service\Archive\DuckDbCliColdQueryRunner;

final class DuckDbCliColdQueryRunnerTest extends TestCase
{
    public function testRunsReadOnlySelectAgainstDownloadedParquetAndUploadsJsonResult(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage([
            's3://archive/raw/event_type=click/date=2026-06-08/hour=10/part-1.parquet' => 'PAR1clicks',
            's3://archive/raw/event_type=impression/date=2026-06-08/hour=10/part-1.parquet' => 'PAR1impressions',
        ]);
        $runner = new JsonWritingRunner('[{"event_type":"click","events":2}]');
        $tempDirectory = $this->temporaryDirectory();
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $tempDirectory, $runner);

        $result = $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_1',
            sql: 'select event_type, count(*) as events from archive where date = ? group by event_type',
            parameters: ['2026-06-08'],
            objectKeys: [
                's3://archive/raw/event_type=click/date=2026-06-08/hour=10/part-1.parquet',
                's3://archive/raw/event_type=impression/date=2026-06-08/hour=10/part-1.parquet',
            ],
            resultObjectKey: 's3://archive/query-results/cold_query_1.json',
            currentStatus: 'running',
        ));

        self::assertSame('s3://archive/query-results/cold_query_1.json', $result->resultObjectKey);
        self::assertSame('json', $result->resultFormat);
        self::assertSame(1, $result->rowCount);
        self::assertSame([
            's3://archive/raw/event_type=click/date=2026-06-08/hour=10/part-1.parquet',
            's3://archive/raw/event_type=impression/date=2026-06-08/hour=10/part-1.parquet',
        ], $result->scannedObjectKeys);
        self::assertSame('[{"event_type":"click","events":2}]', $storage->puts[0]['body'] ?? null);
        self::assertSame('application/json', $storage->puts[0]['content_type'] ?? null);
        self::assertSame(['duckdb-test', '-batch'], $runner->commands[0]);
        self::assertStringContainsString('CREATE OR REPLACE VIEW archive AS', $runner->stdins[0]);
        self::assertStringContainsString('read_parquet', $runner->stdins[0]);
        self::assertStringContainsString("date = '2026-06-08'", $runner->stdins[0]);
        self::assertStringNotContainsString('?', $runner->stdins[0]);
        self::assertSame([], glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testReturnsEmptyJsonWithoutStartingDuckDbWhenThereAreNoArchivedObjects(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage();
        $runner = new JsonWritingRunner('[{"should_not":"run"}]');
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), $runner);

        $result = $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_empty_archive',
            sql: 'select ? as label, ? as active, ? as missing from archive',
            parameters: ["O'Hare", false, null],
            objectKeys: [],
            resultObjectKey: 's3://archive/query-results/empty-archive.json',
            currentStatus: 'running',
        ));

        self::assertSame(0, $result->rowCount);
        self::assertSame([], $result->scannedObjectKeys);
        self::assertSame('[]', $storage->puts[0]['body'] ?? null);
        self::assertSame('application/json', $storage->puts[0]['content_type'] ?? null);
        self::assertSame([], $runner->commands);
    }

    public function testRejectsUnsafeSqlBeforeDownloadingObjects(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage([
            's3://archive/raw/part-1.parquet' => 'PAR1',
        ]);
        $runner = new JsonWritingRunner('[]');
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), $runner);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DuckDB cold query only accepts a single SELECT statement.');

        $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_unsafe',
            sql: 'select * from archive; drop table raw_events',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/unsafe.json',
            currentStatus: 'running',
        ));
    }

    public function testRejectsSelectsThatDoNotQueryTheArchiveView(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage([
            's3://archive/raw/part-1.parquet' => 'PAR1',
        ]);
        $runner = new JsonWritingRunner('[]');
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), $runner);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DuckDB cold query must read from the archive view.');

        $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_no_archive_view',
            sql: 'select current_database()',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/no-archive-view.json',
            currentStatus: 'running',
        ));
    }

    public function testRejectsBlankTempDirectoryBeforeCreatingWorkspace(): void
    {
        $duckDb = new DuckDbCliColdQueryRunner(
            new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']),
            'duckdb-test',
            '',
            new JsonWritingRunner('[]'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DuckDB cold query temp directory is required.');

        $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_blank_temp',
            sql: 'select * from archive',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/blank-temp.json',
            currentStatus: 'running',
        ));
    }

    public function testReportsTempDirectoryCreationFailure(): void
    {
        $scheme = 'vertoadquerybase';
        ColdQueryDirectoryStreamWrapper::register($scheme, []);
        $duckDb = new DuckDbCliColdQueryRunner(
            new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']),
            'duckdb-test',
            $scheme . '://base',
            new JsonWritingRunner('[]'),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create DuckDB cold query temp directory.');

            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_base_failure',
                sql: 'select * from archive',
                parameters: [],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/base-failure.json',
                currentStatus: 'running',
            ));
        } finally {
            ColdQueryDirectoryStreamWrapper::unregister($scheme);
        }
    }

    public function testReportsWorkspaceCreationFailure(): void
    {
        $scheme = 'vertoadqueryworkspace';
        ColdQueryDirectoryStreamWrapper::register($scheme, [$scheme . '://base']);
        $duckDb = new DuckDbCliColdQueryRunner(
            new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']),
            'duckdb-test',
            $scheme . '://base',
            new JsonWritingRunner('[]'),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create DuckDB cold query workspace.');

            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_workspace_failure',
                sql: 'select * from archive',
                parameters: [],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/workspace-failure.json',
                currentStatus: 'running',
            ));
        } finally {
            ColdQueryDirectoryStreamWrapper::unregister($scheme);
        }
    }

    public function testCleanupIgnoresWorkspaceAlreadyRemovedByDuckDbFailure(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']);
        $duckDb = new DuckDbCliColdQueryRunner(
            $storage,
            'duckdb-test',
            $this->temporaryDirectory(),
            new WorkspaceDeletingColdQueryCommandRunner(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DuckDB cold query failed: workspace deleted');

        $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_deleted_workspace',
            sql: 'select * from archive',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/deleted-workspace.json',
            currentStatus: 'running',
        ));
    }

    public function testRunnerFailureDoesNotUploadResultAndCleansTemporaryFiles(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']);
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), new FailingColdQueryCommandRunner());

        try {
            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_failed',
                sql: 'select * from archive',
                parameters: [],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/failed.json',
                currentStatus: 'running',
            ));
            self::fail('Expected DuckDB cold query failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('DuckDB cold query failed: simulated duckdb failure', $exception->getMessage());
        }

        self::assertSame([], $storage->puts);
    }

    public function testRejectsConstructorAndRequestLimits(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage();

        try {
            new DuckDbCliColdQueryRunner($storage, '', $this->temporaryDirectory());
            self::fail('Expected missing binary validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB binary path is required.', $exception->getMessage());
        }

        try {
            new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), timeoutSeconds: 0);
            self::fail('Expected timeout validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query timeout seconds must be positive.', $exception->getMessage());
        }

        try {
            new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), maxScannedObjects: 0);
            self::fail('Expected scanned object limit validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query scanned object limit must be positive.', $exception->getMessage());
        }

        try {
            new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), maxResultBytes: 0);
            self::fail('Expected result byte limit validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query result byte limit must be positive.', $exception->getMessage());
        }

        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), maxScannedObjects: 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DuckDB cold query scanned object limit exceeded.');

        $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_too_many',
            sql: 'select * from archive',
            parameters: [],
            objectKeys: ['s3://archive/raw/a.parquet', 's3://archive/raw/b.parquet'],
            resultObjectKey: 's3://archive/query-results/too-many.json',
            currentStatus: 'running',
        ));
    }

    public function testRejectsParameterMismatchAndUnsupportedParameterTypes(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']);
        $duckDb = new DuckDbCliColdQueryRunner($storage, 'duckdb-test', $this->temporaryDirectory(), new JsonWritingRunner('[]'));

        try {
            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_extra_param',
                sql: 'select * from archive',
                parameters: ['unused'],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/extra.json',
                currentStatus: 'running',
            ));
            self::fail('Expected extra parameter validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query received more parameters than placeholders.', $exception->getMessage());
        }

        try {
            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_missing_param',
                sql: 'select * from archive where event_type = ?',
                parameters: [],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/missing.json',
                currentStatus: 'running',
            ));
            self::fail('Expected missing parameter validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query has unbound parameter placeholders.', $exception->getMessage());
        }

        try {
            $duckDb->run(new ColdQueryExecutionRequest(
                jobId: 'cold_query_bad_param',
                sql: 'select * from archive where event_type = ?',
                parameters: [['click']],
                objectKeys: ['s3://archive/raw/part-1.parquet'],
                resultObjectKey: 's3://archive/query-results/bad-param.json',
                currentStatus: 'running',
            ));
            self::fail('Expected unsupported parameter validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('DuckDB cold query parameters must be scalar values.', $exception->getMessage());
        }
    }

    public function testRejectsOversizedResultAndHandlesNonArrayJsonRowCount(): void
    {
        $storage = new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']);
        $duckDb = new DuckDbCliColdQueryRunner(
            $storage,
            'duckdb-test',
            $this->temporaryDirectory(),
            new JsonWritingRunner('{"events":1}'),
            maxResultBytes: 100,
        );
        $result = $duckDb->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_object_json',
            sql: 'select count(*) as events from archive where enabled = ? and score >= ? and optional is ?',
            parameters: [true, 1.5, null],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/object-json.json',
            currentStatus: 'running',
        ));

        self::assertSame(1, $result->rowCount);

        $invalidJson = new DuckDbCliColdQueryRunner(
            new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']),
            'duckdb-test',
            $this->temporaryDirectory(),
            new JsonWritingRunner('{not-json'),
            maxResultBytes: 100,
        );
        $invalidResult = $invalidJson->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_invalid_json',
            sql: 'select * from archive',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/invalid-json.json',
            currentStatus: 'running',
        ));

        self::assertSame(0, $invalidResult->rowCount);

        $tooLarge = new DuckDbCliColdQueryRunner(
            new ColdQueryRecordingArchiveObjectStorage(['s3://archive/raw/part-1.parquet' => 'PAR1']),
            'duckdb-test',
            $this->temporaryDirectory(),
            new JsonWritingRunner('[{"large":"payload"}]'),
            maxResultBytes: 5,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DuckDB cold query result exceeds configured byte limit.');

        $tooLarge->run(new ColdQueryExecutionRequest(
            jobId: 'cold_query_large',
            sql: 'select * from archive',
            parameters: [],
            objectKeys: ['s3://archive/raw/part-1.parquet'],
            resultObjectKey: 's3://archive/query-results/large.json',
            currentStatus: 'running',
        ));
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-duckdb-query-' . bin2hex(random_bytes(4));
        mkdir($path);

        return $path;
    }
}

final class ColdQueryRecordingArchiveObjectStorage implements \VertoAD\Service\Archive\ArchiveObjectStorageInterface
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

final class JsonWritingRunner implements ArchiveCommandRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<string> */
    public array $stdins = [];

    public function __construct(private readonly string $json)
    {
    }

    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        $this->commands[] = $command;
        $this->stdins[] = $stdin ?? '';
        if (preg_match("/TO '([^']+)'/i", $stdin ?? '', $matches) !== 1) {
            return new ArchiveCommandResult(1, '', 'missing output path');
        }

        file_put_contents(str_replace("''", "'", $matches[1]), $this->json);

        return new ArchiveCommandResult(0, 'ok', '');
    }
}

final class FailingColdQueryCommandRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        return new ArchiveCommandResult(2, '', 'simulated duckdb failure');
    }
}

final class WorkspaceDeletingColdQueryCommandRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        if (preg_match("/TO '([^']+)'/i", $stdin ?? '', $matches) === 1) {
            $workspace = dirname(str_replace("''", "'", $matches[1]));
            foreach (glob($workspace . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($workspace);
        }

        return new ArchiveCommandResult(2, '', 'workspace deleted');
    }
}

final class ColdQueryDirectoryStreamWrapper
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
