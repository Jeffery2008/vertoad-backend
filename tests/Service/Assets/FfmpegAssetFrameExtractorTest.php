<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;
use VertoAD\Service\Assets\AssetSnapshotProcessingException;
use VertoAD\Service\Assets\FfmpegAssetFrameExtractor;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class FfmpegAssetFrameExtractorTest extends TestCase
{
    public function testExtractsPngFromPrivateMp4BytesThroughStdin(): void
    {
        $runner = new RecordingAssetCommandRunner(new ArchiveCommandResult(0, AssetTestFixtures::image(), ''));
        $extractor = new FfmpegAssetFrameExtractor($runner, 'ffmpeg-test', 17);
        $bytes = "\x00\x00\x00\x18ftypmp42payload";

        self::assertSame('image/png', getimagesizefromstring($extractor->extract($bytes, 'video/mp4', 1920, 1080))['mime']);
        self::assertSame($bytes, $runner->stdin);
        self::assertSame(17, $runner->timeout);
        self::assertSame('ffmpeg-test', $runner->command[0] ?? null);
        self::assertContains('pipe:0', $runner->command);
        self::assertContains('pipe', $runner->command);
        self::assertContains('scale=w=min(1920\,iw):h=min(1080\,ih):force_original_aspect_ratio=decrease', $runner->command);
        self::assertNotContains('https', $runner->command);
    }

    public function testAcceptsWebmAndRejectsInvalidMetadataOrContainer(): void
    {
        $runner = new RecordingAssetCommandRunner(new ArchiveCommandResult(0, AssetTestFixtures::image(), ''));
        $extractor = new FfmpegAssetFrameExtractor($runner);
        self::assertNotSame('', $extractor->extract("\x1A\x45\xDF\xA3payload", 'video/webm', 1, 1));

        foreach ([
            ['', 'video/mp4', 1, 1, 'asset_video_source_invalid'],
            ["\x00\x00\x00\x18ftypmp42", 'video/avi', 1, 1, 'asset_video_source_invalid'],
            ["\x00\x00\x00\x18ftypmp42", 'video/mp4', 0, 1, 'asset_video_source_invalid'],
            ["\x00\x00\x00\x18ftypmp42", 'video/mp4', 1, 0, 'asset_video_source_invalid'],
            ['short', 'video/mp4', 1, 1, 'asset_video_content_changed'],
            ['wrong', 'video/webm', 1, 1, 'asset_video_content_changed'],
        ] as [$bytes, $mime, $width, $height, $code]) {
            try {
                $extractor->extract($bytes, $mime, $width, $height);
                self::fail('Expected invalid video source.');
            } catch (AssetSnapshotProcessingException $exception) {
                self::assertSame($code, $exception->errorCode);
                self::assertFalse($exception->retryable);
            }
        }
    }

    public function testReportsRetryableFfmpegAndOutputFailures(): void
    {
        foreach ([
            [new ArchiveCommandResult(1, '', 'failed'), 'asset_video_frame_extract_failed'],
            [new ArchiveCommandResult(0, 'not-png', ''), 'asset_video_frame_invalid'],
        ] as [$result, $code]) {
            $extractor = new FfmpegAssetFrameExtractor(new RecordingAssetCommandRunner($result));
            try {
                $extractor->extract("\x00\x00\x00\x18ftypmp42payload", 'video/mp4', 100, 100);
                self::fail('Expected FFmpeg failure.');
            } catch (AssetSnapshotProcessingException $exception) {
                self::assertSame($code, $exception->errorCode);
                self::assertTrue($exception->retryable);
            }
        }
    }

    public function testRejectsInvalidExtractorConfiguration(): void
    {
        foreach ([['', 1], ['ffmpeg', 0]] as [$binary, $timeout]) {
            try {
                new FfmpegAssetFrameExtractor(binary: $binary, timeoutSeconds: $timeout);
                self::fail('Expected invalid FFmpeg configuration.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}

final class RecordingAssetCommandRunner implements ArchiveCommandRunnerInterface
{
    /** @var list<string> */
    public array $command = [];
    public ?string $stdin = null;
    public int $timeout = 0;

    public function __construct(private readonly ArchiveCommandResult $result)
    {
    }

    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        $this->command = $command;
        $this->stdin = $stdin;
        $this->timeout = $timeoutSeconds;

        return $this->result;
    }
}
