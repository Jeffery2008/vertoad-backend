<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets {
    use GdImage;

    function extension_loaded(string $extension): bool
    {
        if ($extension === 'gd') {
            return \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::$gdLoaded;
        }

        return \extension_loaded($extension);
    }

    function function_exists(string $function): bool
    {
        if ($function === 'imagewebp') {
            return \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::$webpAvailable;
        }

        return \function_exists($function);
    }

    function imagecreatefromstring(string $data): GdImage|false
    {
        if (\VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::$failDecode) {
            return false;
        }

        return \imagecreatefromstring($data);
    }

    function imagecreatetruecolor(int $width, int $height): GdImage|false
    {
        $native = \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::class;
        ++$native::$canvasCalls;
        if ($native::$failCanvasCall === $native::$canvasCalls) {
            return false;
        }

        return \imagecreatetruecolor($width, $height);
    }

    /** @param resource|string|null $file */
    function imagepng(GdImage $image, mixed $file = null, int $quality = -1, int $filters = -1): bool
    {
        $mode = \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::$pngMode;
        if ($mode === 'false') {
            return false;
        }
        if ($mode === 'empty') {
            return true;
        }

        return \imagepng($image, $file, $quality, $filters);
    }

    /** @param resource|string|null $file */
    function imagewebp(GdImage $image, mixed $file = null, int $quality = -1): bool
    {
        $native = \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::class;
        ++$native::$webpCalls;
        $mode = $native::$webpModes[$native::$webpCalls] ?? 'normal';
        if ($mode === 'false') {
            return false;
        }
        if ($mode === 'empty') {
            return true;
        }

        return \imagewebp($image, $file, $quality);
    }

    function ob_get_clean(): string|false
    {
        $native = \VertoAD\Tests\Service\Assets\AssetSnapshotGeneratorNative::class;
        ++$native::$bufferReads;
        if ($native::$failBufferRead === $native::$bufferReads) {
            \ob_end_clean();

            return false;
        }

        return \ob_get_clean();
    }
}

namespace VertoAD\Tests\Service\Assets {

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;
use VertoAD\Service\Assets\AssetSnapshotGenerator;
use VertoAD\Service\Assets\AssetSnapshotProcessingException;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;
use VertoAD\Service\Assets\FfmpegAssetFrameExtractor;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class AssetSnapshotGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        AssetSnapshotGeneratorNative::reset();
    }

    protected function tearDown(): void
    {
        AssetSnapshotGeneratorNative::reset();
    }

    public function testRejectsEveryInvalidConfigurationAndMissingGdCapability(): void
    {
        foreach ([
            ['maxWidth' => 0],
            ['maxHeight' => 0],
            ['thumbnailMaxWidth' => 0],
            ['thumbnailMaxHeight' => 0],
            ['webpQuality' => 0],
            ['webpQuality' => 101],
        ] as $config) {
            try {
                $this->generator($config);
                self::fail('Expected invalid snapshot generator configuration.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset snapshot dimensions and WebP quality are invalid.', $exception->getMessage());
            }
        }

        AssetSnapshotGeneratorNative::$gdLoaded = false;
        $this->assertConstructorRuntimeFailure();

        AssetSnapshotGeneratorNative::$gdLoaded = true;
        AssetSnapshotGeneratorNative::$webpAvailable = false;
        $this->assertConstructorRuntimeFailure();

        AssetSnapshotGeneratorNative::reset();
        self::assertInstanceOf(AssetSnapshotGenerator::class, $this->generator());
    }

    public function testGeneratesScaledImageArtifactsWithStableOrganizationScopedKeys(): void
    {
        $body = AssetTestFixtures::image(width: 8, height: 4);
        $asset = $this->asset(AssetType::Image, $body, width: 8, height: 4);
        $generator = $this->generator([
            'maxWidth' => 4,
            'maxHeight' => 4,
            'thumbnailMaxWidth' => 2,
            'thumbnailMaxHeight' => 2,
        ]);

        $artifacts = $generator->generate($asset, $body);
        $second = $generator->generate($asset, $body);

        self::assertSame([4, 2, 2, 1], [
            $artifacts->width,
            $artifacts->height,
            $artifacts->thumbnailWidth,
            $artifacts->thumbnailHeight,
        ]);
        self::assertSame('image/png', getimagesizefromstring($artifacts->pngBytes)['mime'] ?? null);
        self::assertSame('image/webp', getimagesizefromstring($artifacts->webpBytes)['mime'] ?? null);
        self::assertSame($artifacts->pngObjectKey, $second->pngObjectKey);
        self::assertStringStartsWith('organizations/99/assets/derived/11/', $artifacts->pngObjectKey);
        self::assertStringEndsWith('/thumbnail.webp', $artifacts->thumbnailWebpObjectKey);

        $thinBody = AssetTestFixtures::image(width: 1, height: 100);
        $thin = $this->generator([
            'maxWidth' => 1,
            'maxHeight' => 1,
            'thumbnailMaxWidth' => 1,
            'thumbnailMaxHeight' => 1,
        ])->generate($this->asset(AssetType::Image, $thinBody, width: 1, height: 100), $thinBody);
        self::assertSame([1, 1, 1, 1], [$thin->width, $thin->height, $thin->thumbnailWidth, $thin->thumbnailHeight]);

        $wideBody = AssetTestFixtures::image(width: 100, height: 1);
        $wide = $this->generator([
            'maxWidth' => 1,
            'maxHeight' => 1,
            'thumbnailMaxWidth' => 1,
            'thumbnailMaxHeight' => 1,
        ])->generate($this->asset(AssetType::Image, $wideBody, width: 100, height: 1), $wideBody);
        self::assertSame([1, 1, 1, 1], [$wide->width, $wide->height, $wide->thumbnailWidth, $wide->thumbnailHeight]);
    }

    public function testGeneratesTextFabricAndVideoSnapshots(): void
    {
        $text = "First controlled line\n" . str_repeat('review copy ', 80);
        $textArtifacts = $this->generator()->generate($this->asset(AssetType::Text, $text), $text);
        self::assertSame([600, 200], [$textArtifacts->width, $textArtifacts->height]);

        $fabric = AssetTestFixtures::fabric([
            ['id' => 'headline', 'type' => 'textbox', 'text' => 'Controlled creative'],
        ]);
        $fabricArtifacts = $this->generator()->generate(
            $this->asset(AssetType::FabricSnapshot, $fabric, width: 4, height: 3),
            $fabric,
        );
        self::assertSame([4, 3], [$fabricArtifacts->width, $fabricArtifacts->height]);

        $video = "\x00\x00\x00\x18ftypmp42private-video";
        $runner = new SnapshotFrameRunner(AssetTestFixtures::image(width: 6, height: 4));
        $videoArtifacts = $this->generator(frameRunner: $runner)->generate(
            $this->asset(AssetType::Video, $video, contentType: 'video/mp4', width: 1920, height: 1080),
            $video,
        );
        self::assertSame([6, 4], [$videoArtifacts->width, $videoArtifacts->height]);
        self::assertSame($video, $runner->stdin);
    }

    public function testRejectsInvalidIdentityMissingOrChangedSourceBytes(): void
    {
        $body = AssetTestFixtures::image();
        $asset = $this->asset(AssetType::Image, $body);
        $cases = [
            [$this->copyAsset($asset, ['id' => null]), $body, 'asset_snapshot_identity_invalid', false],
            [$this->copyAsset($asset, ['id' => 0]), $body, 'asset_snapshot_identity_invalid', false],
            [$asset, null, 'asset_snapshot_source_missing', true],
            [$asset, '', 'asset_snapshot_source_missing', true],
            [$this->copyAsset($asset, ['byteSize' => strlen($body) + 1]), $body, 'asset_snapshot_source_size_changed', false],
            [$this->copyAsset($asset, ['checksum' => null]), $body, 'asset_snapshot_source_checksum_changed', false],
            [$this->copyAsset($asset, ['checksum' => 'sha256:' . str_repeat('0', 64)]), $body, 'asset_snapshot_source_checksum_changed', false],
        ];

        foreach ($cases as [$candidate, $source, $code, $retryable]) {
            $this->assertProcessingFailure($this->generator(), $candidate, $source, $code, $retryable);
        }
    }

    public function testRejectsChangedImageFabricAndTextContent(): void
    {
        $invalidImage = 'not-an-image';
        $this->assertProcessingFailure(
            $this->generator(),
            $this->asset(AssetType::Image, $invalidImage),
            $invalidImage,
            'asset_image_content_changed',
            false,
        );

        $jpeg = AssetTestFixtures::image('image/jpeg');
        $this->assertProcessingFailure(
            $this->generator(),
            $this->asset(AssetType::Image, $jpeg, contentType: 'image/png'),
            $jpeg,
            'asset_image_content_changed',
            false,
        );

        $image = AssetTestFixtures::image();
        foreach ([['width' => 5], ['height' => 4]] as $override) {
            $this->assertProcessingFailure(
                $this->generator(),
                $this->copyAsset($this->asset(AssetType::Image, $image), $override),
                $image,
                'asset_image_dimensions_changed',
                false,
            );
        }

        $fabric = AssetTestFixtures::fabric();
        $this->assertProcessingFailure(
            $this->generator(),
            $this->asset(AssetType::FabricSnapshot, $fabric, contentType: 'text/plain'),
            $fabric,
            'asset_fabric_content_type_invalid',
            false,
        );
        $invalidFabric = '{';
        $this->assertProcessingFailure(
            $this->generator(),
            $this->asset(AssetType::FabricSnapshot, $invalidFabric),
            $invalidFabric,
            'asset_fabric_payload_invalid',
            false,
        );
        foreach ([['width' => 5], ['height' => 4]] as $override) {
            $this->assertProcessingFailure(
                $this->generator(),
                $this->copyAsset($this->asset(AssetType::FabricSnapshot, $fabric), $override),
                $fabric,
                'asset_fabric_dimensions_changed',
                false,
            );
        }

        foreach ([
            ['plain text', 'application/octet-stream'],
            ["plain\0text", 'text/plain'],
        ] as [$text, $contentType]) {
            $this->assertProcessingFailure(
                $this->generator(),
                $this->asset(AssetType::Text, $text, contentType: $contentType),
                $text,
                'asset_text_content_invalid',
                false,
            );
        }
    }

    public function testReportsDecodeCanvasAndEncodingFailuresWithRetryPolicy(): void
    {
        $body = AssetTestFixtures::image();
        $asset = $this->asset(AssetType::Image, $body);
        $generator = $this->generator();

        AssetSnapshotGeneratorNative::$failDecode = true;
        $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_decode_failed', false);
        AssetSnapshotGeneratorNative::reset();

        AssetSnapshotGeneratorNative::$failCanvasCall = 1;
        $this->assertProcessingFailure(
            $generator,
            $this->asset(AssetType::Text, 'copy'),
            'copy',
            'asset_text_snapshot_failed',
            true,
        );
        AssetSnapshotGeneratorNative::reset();

        foreach ([1, 2] as $canvasCall) {
            AssetSnapshotGeneratorNative::$failCanvasCall = $canvasCall;
            $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_canvas_failed', true);
            AssetSnapshotGeneratorNative::reset();
        }

        foreach (['false', 'empty'] as $mode) {
            AssetSnapshotGeneratorNative::$pngMode = $mode;
            $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_png_encode_failed', true);
            AssetSnapshotGeneratorNative::reset();
        }
        AssetSnapshotGeneratorNative::$failBufferRead = 1;
        $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_png_encode_failed', true);
        AssetSnapshotGeneratorNative::reset();

        foreach ([
            [1, 'false'],
            [1, 'empty'],
            [2, 'false'],
        ] as [$call, $mode]) {
            AssetSnapshotGeneratorNative::$webpModes[$call] = $mode;
            $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_webp_encode_failed', true);
            AssetSnapshotGeneratorNative::reset();
        }

        AssetSnapshotGeneratorNative::$webpModes[1] = 'empty';
        AssetSnapshotGeneratorNative::$failBufferRead = 2;
        $this->assertProcessingFailure($generator, $asset, $body, 'asset_snapshot_webp_encode_failed', true);
    }

    /** @param array<string, int> $config */
    private function generator(array $config = [], ?SnapshotFrameRunner $frameRunner = null): AssetSnapshotGenerator
    {
        return new AssetSnapshotGenerator(
            fabricValidator: new FabricCreativePayloadValidator(),
            videoFrames: new FfmpegAssetFrameExtractor(
                $frameRunner ?? new SnapshotFrameRunner(AssetTestFixtures::image()),
                'ffmpeg-test',
                5,
            ),
            maxWidth: $config['maxWidth'] ?? 1920,
            maxHeight: $config['maxHeight'] ?? 1920,
            thumbnailMaxWidth: $config['thumbnailMaxWidth'] ?? 640,
            thumbnailMaxHeight: $config['thumbnailMaxHeight'] ?? 640,
            webpQuality: $config['webpQuality'] ?? 85,
        );
    }

    private function asset(
        AssetType $type,
        string $body,
        ?string $contentType = null,
        int $width = 4,
        int $height = 3,
    ): CreativeAsset {
        $contentType ??= match ($type) {
            AssetType::Image => 'image/png',
            AssetType::Video => 'video/mp4',
            AssetType::FabricSnapshot => 'application/json',
            AssetType::Text => 'text/plain',
        };

        return new CreativeAsset(
            id: 11,
            uploadIntentId: 7,
            organizationId: 99,
            uploaderUserId: 5,
            type: $type,
            objectKey: 'organizations/99/assets/source.bin',
            contentType: $contentType,
            byteSize: strlen($body),
            width: $width,
            height: $height,
            durationSeconds: $type === AssetType::Video ? 12.5 : null,
            checksum: 'sha256:' . hash('sha256', $body),
            status: AssetStatus::PendingReview,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function copyAsset(CreativeAsset $asset, array $overrides): CreativeAsset
    {
        return new CreativeAsset(...array_replace([
            'id' => $asset->id,
            'uploadIntentId' => $asset->uploadIntentId,
            'organizationId' => $asset->organizationId,
            'uploaderUserId' => $asset->uploaderUserId,
            'type' => $asset->type,
            'objectKey' => $asset->objectKey,
            'contentType' => $asset->contentType,
            'byteSize' => $asset->byteSize,
            'width' => $asset->width,
            'height' => $asset->height,
            'durationSeconds' => $asset->durationSeconds,
            'checksum' => $asset->checksum,
            'status' => $asset->status,
            'snapshotStatus' => $asset->snapshotStatus,
            'snapshotPngObjectKey' => $asset->snapshotPngObjectKey,
            'snapshotWebpObjectKey' => $asset->snapshotWebpObjectKey,
            'thumbnailWebpObjectKey' => $asset->thumbnailWebpObjectKey,
        ], $overrides));
    }

    private function assertProcessingFailure(
        AssetSnapshotGenerator $generator,
        CreativeAsset $asset,
        ?string $body,
        string $code,
        bool $retryable,
    ): void {
        try {
            $generator->generate($asset, $body);
            self::fail('Expected asset snapshot processing failure.');
        } catch (AssetSnapshotProcessingException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($retryable, $exception->retryable);
        }
    }

    private function assertConstructorRuntimeFailure(): void
    {
        try {
            $this->generator();
            self::fail('Expected unavailable GD snapshot support.');
        } catch (RuntimeException $exception) {
            self::assertSame('The GD extension with WebP support is required for asset snapshots.', $exception->getMessage());
        }
    }
}

final class AssetSnapshotGeneratorNative
{
    public static bool $gdLoaded = true;
    public static bool $webpAvailable = true;
    public static bool $failDecode = false;
    public static int $canvasCalls = 0;
    public static ?int $failCanvasCall = null;
    public static string $pngMode = 'normal';
    public static int $webpCalls = 0;

    /** @var array<int, string> */
    public static array $webpModes = [];

    public static int $bufferReads = 0;
    public static ?int $failBufferRead = null;

    public static function reset(): void
    {
        self::$gdLoaded = true;
        self::$webpAvailable = true;
        self::$failDecode = false;
        self::$canvasCalls = 0;
        self::$failCanvasCall = null;
        self::$pngMode = 'normal';
        self::$webpCalls = 0;
        self::$webpModes = [];
        self::$bufferReads = 0;
        self::$failBufferRead = null;
    }
}

final class SnapshotFrameRunner implements ArchiveCommandRunnerInterface
{
    public ?string $stdin = null;

    public function __construct(private readonly string $frame)
    {
    }

    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        $this->stdin = $stdin;

        return new ArchiveCommandResult(0, $this->frame, '');
    }
}
}
