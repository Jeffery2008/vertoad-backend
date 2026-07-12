<?php

declare(strict_types=1);

namespace VertoAD\Tests\Integration\ExternalTools;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Service\Archive\ArchiveObjectStorageInterface;
use VertoAD\Service\Archive\ColdQueryExecutionRequest;
use VertoAD\Service\Archive\DuckDbCliArchiveWriter;
use VertoAD\Service\Archive\DuckDbCliColdQueryRunner;
use VertoAD\Service\Archive\ProcessArchiveCommandRunner;
use VertoAD\Service\Assets\FfmpegAssetFrameExtractor;

#[Group('external-tools-integration')]
final class ExternalToolsIntegrationTest extends TestCase
{
    public function testProjectArchiveClassesWriteAndReadRealParquetWithDuckDb(): void
    {
        $binary = $this->requiredBinary('ARCHIVE_DUCKDB_BINARY');
        $commandRunner = new ProcessArchiveCommandRunner();
        $version = $commandRunner->run([$binary, '--version'], null, 15);
        self::assertSame(0, $version->exitCode, $version->stderr);
        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+\b/', trim($version->stdout));

        $storage = new ExternalToolsObjectStorage();
        $tempDirectory = $this->temporaryDirectory('duckdb');
        $writer = new DuckDbCliArchiveWriter($storage, $binary, $tempDirectory, $commandRunner, 30);
        $objectKey = 's3://vertoad-integration/raw/event_type=click/date=2026-07-11/hour=17/part.parquet';

        try {
            $manifest = $writer->writePartition(
                'event_type=click/date=2026-07-11/hour=17',
                $objectKey,
                [
                    new ArchiveEvent('click', 'evt-click-1', new DateTimeImmutable('2026-07-11T17:00:01Z'), ['cost_points' => 20]),
                    new ArchiveEvent('impression', 'evt-impression-1', new DateTimeImmutable('2026-07-11T17:00:02Z'), ['cost_points' => 10]),
                ],
            );

            $parquet = $storage->get($objectKey);
            self::assertStringStartsWith('PAR1', $parquet);
            self::assertStringEndsWith('PAR1', $parquet);
            self::assertSame(2, $manifest->rowCount);
            self::assertSame(strlen($parquet), $manifest->byteCount);
            self::assertSame('sha256:' . hash('sha256', $parquet), $manifest->checksum);

            $resultKey = 's3://vertoad-integration/results/query.json';
            $queryRunner = new DuckDbCliColdQueryRunner($storage, $binary, $tempDirectory, $commandRunner, 30);
            $result = $queryRunner->run(new ColdQueryExecutionRequest(
                jobId: 'cold-query-integration',
                sql: 'SELECT event_id, event_type FROM archive ORDER BY event_id',
                parameters: [],
                objectKeys: [$objectKey],
                resultObjectKey: $resultKey,
                currentStatus: 'processing',
            ));

            $rows = json_decode($storage->get($resultKey), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(2, $result->rowCount);
            self::assertSame([$objectKey], $result->scannedObjectKeys);
            self::assertSame([
                ['event_id' => 'evt-click-1', 'event_type' => 'click'],
                ['event_id' => 'evt-impression-1', 'event_type' => 'impression'],
            ], $rows);
            self::assertSame([], glob($tempDirectory . DIRECTORY_SEPARATOR . 'archive-writer-*') ?: []);
            self::assertSame([], glob($tempDirectory . DIRECTORY_SEPARATOR . 'cold-query-*') ?: []);
        } finally {
            @rmdir($tempDirectory);
        }
    }

    public function testProjectVideoExtractorDecodesARealFfmpegGeneratedMp4Frame(): void
    {
        $binary = $this->requiredBinary('CRON_ASSET_SNAPSHOT_FFMPEG_BINARY');
        $commandRunner = new ProcessArchiveCommandRunner();
        $version = $commandRunner->run([$binary, '-version'], null, 15);
        self::assertSame(0, $version->exitCode, $version->stderr);
        self::assertStringContainsStringIgnoringCase('ffmpeg version', $version->stdout);

        $video = $commandRunner->run([
            $binary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=c=red:s=64x48:d=1',
            '-frames:v',
            '1',
            '-an',
            '-c:v',
            'mpeg4',
            '-movflags',
            'frag_keyframe+empty_moov',
            '-f',
            'mp4',
            'pipe:1',
        ], null, 30);
        self::assertSame(0, $video->exitCode, $video->stderr);
        self::assertSame('ftyp', substr($video->stdout, 4, 4));

        $extractor = new FfmpegAssetFrameExtractor($commandRunner, $binary, 30);
        $png = $extractor->extract($video->stdout, 'video/mp4', 32, 24);
        $image = getimagesizefromstring($png);

        self::assertIsArray($image);
        self::assertSame('image/png', $image['mime'] ?? null);
        self::assertSame(32, $image[0] ?? null);
        self::assertSame(24, $image[1] ?? null);
    }

    private function requiredBinary(string $environmentVariable): string
    {
        $binary = trim((string) getenv($environmentVariable));
        self::assertNotSame('', $binary, $environmentVariable . ' must name the real executable under test.');

        return $binary;
    }

    private function temporaryDirectory(string $suffix): string
    {
        $directory = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'build'
            . DIRECTORY_SEPARATOR . 'external-tools'
            . DIRECTORY_SEPARATOR . $suffix . '-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0777, true));

        return $directory;
    }
}

final class ExternalToolsObjectStorage implements ArchiveObjectStorageInterface
{
    /** @var array<string, string> */
    private array $objects = [];

    public function put(string $objectKey, string $body, string $contentType): void
    {
        if ($contentType === '') {
            throw new \InvalidArgumentException('Integration storage content type is required.');
        }
        $this->objects[$objectKey] = $body;
    }

    public function get(string $objectKey): string
    {
        if (!array_key_exists($objectKey, $this->objects)) {
            throw new \RuntimeException('Integration storage object was not found: ' . $objectKey);
        }

        return $this->objects[$objectKey];
    }
}
