<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage {
    function file_put_contents(string $filename, mixed $data, int $flags = 0, mixed $context = null): int|false
    {
        $mode = \VertoAD\Tests\Service\Assets\S3AssetStorageNative::$fileWriteMode;
        if ($mode === 'false') {
            return false;
        }
        if ($mode === 'throw') {
            throw new \RuntimeException('injected local video probe write failure');
        }

        return $context === null
            ? \file_put_contents($filename, $data, $flags)
            : \file_put_contents($filename, $data, $flags, $context);
    }
}

namespace VertoAD\Tests\Service\Assets {

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use VertoAD\Infrastructure\Storage\S3AssetObjectStorage;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class S3AssetObjectStorageTest extends TestCase
{
    protected function setUp(): void
    {
        S3AssetStorageNative::$fileWriteMode = 'normal';
    }

    protected function tearDown(): void
    {
        S3AssetStorageNative::$fileWriteMode = 'normal';
    }

    public function testValidatesConfigurationAndConstructsTheDefaultS3Client(): void
    {
        foreach ([
            ['endpoint' => '', 'bucket' => 'assets', 'access_key_id' => 'key', 'secret_access_key' => 'secret'],
            ['endpoint' => 'https://s3.test', 'bucket' => '', 'access_key_id' => 'key', 'secret_access_key' => 'secret'],
            ['endpoint' => 'https://s3.test', 'bucket' => 'assets', 'access_key_id' => '', 'secret_access_key' => 'secret'],
            ['endpoint' => 'https://s3.test', 'bucket' => 'assets', 'access_key_id' => 'key', 'secret_access_key' => ''],
        ] as $config) {
            $this->assertInvalidConfiguration(
                $config,
                'Configured S3 endpoint, bucket, and credentials are required for asset storage.',
            );
        }
        $this->assertInvalidConfiguration(
            $this->config(['max_read_bytes' => 0]),
            'Asset storage read limit and snapshot cache control must be configured.',
        );
        $this->assertInvalidConfiguration(
            $this->config(['snapshot_cache_control' => '  ']),
            'Asset storage read limit and snapshot cache control must be configured.',
        );
        $this->assertInvalidConfiguration(
            $this->config(['server_side_encryption' => 'AES128']),
            'Asset S3 server-side encryption must be AES256, aws:kms, or R2-AES256.',
        );

        self::assertInstanceOf(S3AssetObjectStorage::class, new S3AssetObjectStorage($this->config([
            'region' => '',
            'path_style_endpoint' => false,
        ])));
    }

    public function testReadsAndInspectsImageTextAndVideoObjects(): void
    {
        $png = AssetTestFixtures::image(width: 4, height: 3);
        $readMock = $this->objectMock($png, 'Image/PNG; charset=binary', (string) strlen($png));
        $read = $this->storage($readMock)->read('organizations/99/assets/source.png');
        self::assertSame($png, $read);
        self::assertSame('GetObject', $readMock->getLastCommand()?->getName());

        $imageMock = $this->objectMock($png, 'Image/PNG; charset=binary');
        $image = $this->storage($imageMock)->inspect('organizations/99/assets/source.png');
        self::assertNotNull($image);
        self::assertSame('image/png', $image->contentType);
        self::assertSame([4, 3, null], [$image->width, $image->height, $image->durationSeconds]);
        self::assertSame('sha256:' . hash('sha256', $png), $image->checksum);
        self::assertSame(substr($png, 0, 512), $image->leadingBytes);
        self::assertSame($png, $image->body);

        $textClient = new class extends S3Client {
            public function __construct()
            {
            }

            /** @param array<string, mixed> $args */
            public function headObject(array $args): Result
            {
                return new Result(['ContentLength' => 5, 'ContentType' => ' ; charset=binary']);
            }

            /** @param array<string, mixed> $args */
            public function getObject(array $args): Result
            {
                return new Result(['Body' => 'hello']);
            }
        };
        $text = $this->storageWithClient($textClient)->inspect('organizations/99/assets/copy.txt');
        self::assertSame('application/octet-stream', $text?->contentType);
        self::assertSame([1, 1, null], [$text?->width, $text?->height, $text?->durationSeconds]);

        $captured = [];
        $videoBody = "\x00\x00\x00\x18ftypmp42video";
        $videoMock = $this->objectMock($videoBody, 'video/mp4');
        $video = $this->storage(
            $videoMock,
            videoProbe: static function (string $body, string $key, string $contentType) use (&$captured): array {
                $captured = [$body, $key, $contentType];

                return ['width' => 640, 'height' => 360, 'duration_seconds' => 12.5];
            },
        )->inspect('organizations/99/assets/clip.mp4');
        self::assertSame([640, 360, 12.5], [$video?->width, $video?->height, $video?->durationSeconds]);
        self::assertSame([$videoBody, 'organizations/99/assets/clip.mp4', 'video/mp4'], $captured);

        $invalidImage = $this->storage($this->objectMock('not-an-image', 'image/png'))
            ->inspect('organizations/99/assets/invalid.png');
        self::assertSame([0, 0], [$invalidImage?->width, $invalidImage?->height]);
    }

    public function testReturnsNullOnlyForMissingInspectionObjectsAndWrapsHeadFailures(): void
    {
        $missingByStatus = new MockHandler();
        $missingByStatus->append($this->awsFailure('HeadObject', 404, 'Unknown'));
        self::assertNull($this->storage($missingByStatus)->inspect('organizations/99/assets/missing.png'));

        $missingByCode = new MockHandler();
        $missingByCode->append($this->awsFailure('HeadObject', 400, 'NoSuchKey'));
        self::assertNull($this->storage($missingByCode)->inspect('organizations/99/assets/missing-by-code.png'));

        $readMissing = new MockHandler();
        $readMissing->append($this->awsFailure('HeadObject', 404, 'NotFound'));
        $this->assertRuntimeMessage(
            fn () => $this->storage($readMissing)->read('organizations/99/assets/missing-read.png'),
            'Asset object storage metadata read failed.',
        );

        $denied = new MockHandler();
        $denied->append($this->awsFailure('HeadObject', 403, 'AccessDenied'));
        $this->assertRuntimeMessage(
            fn () => $this->storage($denied)->inspect('organizations/99/assets/denied.png'),
            'Asset object storage metadata read failed.',
        );

        $transportFailure = new class extends S3Client {
            public function __construct()
            {
            }

            /** @param array<string, mixed> $args */
            public function headObject(array $args): Result
            {
                throw new RuntimeException('transport details');
            }
        };
        $this->assertRuntimeMessage(
            fn () => $this->storageWithClient($transportFailure)->inspect('organizations/99/assets/transport.png'),
            'Asset object storage metadata read failed.',
        );
    }

    public function testRejectsInvalidLengthAndRequiredEncryptionBeforeDownloading(): void
    {
        foreach ([
            [null, 100],
            ['unknown', 100],
            [0, 100],
            [101, 100],
        ] as [$length, $maxReadBytes]) {
            $mock = new MockHandler();
            $mock->append(new Result(['ContentLength' => $length]));
            $this->assertRuntimeMessage(
                fn () => $this->storage($mock, maxReadBytes: $maxReadBytes)
                    ->inspect('organizations/99/assets/invalid-length.bin'),
                'Asset object length is invalid or exceeds the configured read limit.',
            );
            self::assertSame('HeadObject', $mock->getLastCommand()?->getName());
        }

        $encryptedBody = 'encrypted';
        $encrypted = $this->objectMock($encryptedBody, 'text/plain', encryption: 'AES256');
        self::assertNotNull($this->storage($encrypted, encryption: 'AES256')
            ->inspect('organizations/99/assets/encrypted.txt'));

        foreach ([null, 'aws:kms'] as $actual) {
            $mismatch = new MockHandler();
            $head = ['ContentLength' => strlen($encryptedBody), 'ContentType' => 'text/plain'];
            if ($actual !== null) {
                $head['ServerSideEncryption'] = $actual;
            }
            $mismatch->append(new Result($head));
            $this->assertRuntimeMessage(
                fn () => $this->storage($mismatch, encryption: 'AES256')
                    ->inspect('organizations/99/assets/unencrypted.txt'),
                'Asset object does not use the required server-side encryption.',
            );
            self::assertSame('HeadObject', $mismatch->getLastCommand()?->getName());
        }
    }

    public function testWrapsGetFailuresAndRejectsUnreadableOrChangedBodies(): void
    {
        $missing = new MockHandler();
        $missing->append(new Result(['ContentLength' => 5]));
        $missing->append($this->awsFailure('GetObject', 404, 'NoSuchKey'));
        self::assertNull($this->storage($missing)->inspect('organizations/99/assets/disappeared.bin'));

        $readMissing = new MockHandler();
        $readMissing->append(new Result(['ContentLength' => 5]));
        $readMissing->append($this->awsFailure('GetObject', 404, 'NotFound'));
        $this->assertRuntimeMessage(
            fn () => $this->storage($readMissing)->read('organizations/99/assets/disappeared-read.bin'),
            'Asset object storage content read failed.',
        );

        $provider = new MockHandler();
        $provider->append(new Result(['ContentLength' => 5]));
        $provider->append($this->awsFailure('GetObject', 500, 'InternalError'));
        $this->assertRuntimeMessage(
            fn () => $this->storage($provider)->inspect('organizations/99/assets/provider.bin'),
            'Asset object storage content read failed.',
        );

        $getTransport = new class extends S3Client {
            public function __construct()
            {
            }

            /** @param array<string, mixed> $args */
            public function headObject(array $args): Result
            {
                return new Result(['ContentLength' => 5]);
            }

            /** @param array<string, mixed> $args */
            public function getObject(array $args): Result
            {
                throw new RuntimeException('transport details');
            }
        };
        $this->assertRuntimeMessage(
            fn () => $this->storageWithClient($getTransport)->inspect('organizations/99/assets/transport.bin'),
            'Asset object storage content read failed.',
        );

        $missingBody = new MockHandler();
        $missingBody->append(new Result(['ContentLength' => 5]));
        $missingBody->append(new Result([]));
        $this->assertRuntimeMessage(
            fn () => $this->storage($missingBody)->inspect('organizations/99/assets/body.bin'),
            'Asset object storage returned no readable object body.',
        );

        $oversizedString = new MockHandler();
        $oversizedString->append(new Result(['ContentLength' => 1]));
        $oversizedString->append(new Result(['Body' => str_repeat('x', 17)]));
        $this->assertRuntimeMessage(
            fn () => $this->storage($oversizedString, maxReadBytes: 16)
                ->inspect('organizations/99/assets/oversized-string.bin'),
            'Asset object storage object exceeds the configured read limit.',
        );

        $emptyStream = new MockHandler();
        $emptyStream->append(new Result(['ContentLength' => 1]));
        $emptyStream->append(new Result(['Body' => Utils::streamFor('')]));
        $this->assertRuntimeMessage(
            fn () => $this->storage($emptyStream)->inspect('organizations/99/assets/empty-stream.bin'),
            'Asset object length changed while it was being read.',
        );

        foreach ([new RuntimeException('stream details'), new \Error('stream error details')] as $failure) {
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('eof')->willReturn(false);
            $stream->method('read')->willThrowException($failure);
            $failedStream = new MockHandler();
            $failedStream->append(new Result(['ContentLength' => 5]));
            $failedStream->append(new Result(['Body' => $stream]));
            $this->assertRuntimeMessage(
                fn () => $this->storage($failedStream)->inspect('organizations/99/assets/stream.bin'),
                'Asset object storage body read failed.',
            );
        }

        $overLimitStream = $this->createStub(StreamInterface::class);
        $overLimitStream->method('eof')->willReturn(false);
        $overLimitStream->method('read')->willReturn(str_repeat('x', 17));
        $overLimit = new MockHandler();
        $overLimit->append(new Result(['ContentLength' => 1]));
        $overLimit->append(new Result(['Body' => $overLimitStream]));
        $this->assertRuntimeMessage(
            fn () => $this->storage($overLimit, maxReadBytes: 16)
                ->inspect('organizations/99/assets/grew-after-head.bin'),
            'Asset object storage object exceeds the configured read limit.',
        );

        $changed = new MockHandler();
        $changed->append(new Result(['ContentLength' => 5]));
        $changed->append(new Result(['Body' => 'four']));
        $this->assertRuntimeMessage(
            fn () => $this->storage($changed)->inspect('organizations/99/assets/changed.bin'),
            'Asset object length changed while it was being read.',
        );
    }

    public function testWritesValidatedSnapshotsWithImmutableMetadataAndEncryption(): void
    {
        $png = AssetTestFixtures::image('image/png');
        $webp = AssetTestFixtures::image('image/webp');
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $mock->append(new Result([]));
        $storage = $this->storage($mock, encryption: 'AES256');

        $storage->putSnapshot('organizations/99/assets/derived/1/snapshot.png', $png, 'image/png');
        $pngCommand = $mock->getLastCommand();
        self::assertSame('PutObject', $pngCommand?->getName());
        self::assertSame('creative-assets', $pngCommand?->offsetGet('Bucket'));
        self::assertSame('public, max-age=31536000, immutable', $pngCommand?->offsetGet('CacheControl'));
        self::assertSame('inline', $pngCommand?->offsetGet('ContentDisposition'));
        self::assertSame('AES256', $pngCommand?->offsetGet('ServerSideEncryption'));
        self::assertSame(hash('sha256', $png), $pngCommand?->offsetGet('Metadata')['sha256'] ?? null);

        $storage->putSnapshot('organizations/99/assets/derived/1/snapshot.webp', $webp, 'image/webp');
        self::assertSame('image/webp', $mock->getLastCommand()?->offsetGet('ContentType'));

        $unencrypted = new MockHandler();
        $unencrypted->append(new Result([]));
        $this->storage($unencrypted)->putSnapshot(
            'organizations/99/assets/derived/2/snapshot.png',
            $png,
            'image/png',
        );
        self::assertFalse(isset($unencrypted->getLastCommand()['ServerSideEncryption']));

        foreach ([
            ['', 'image/png'],
            [$png, 'image/webp'],
            [AssetTestFixtures::image('image/jpeg'), 'image/jpeg'],
        ] as [$body, $contentType]) {
            try {
                $this->storage(new MockHandler())->putSnapshot(
                    'organizations/99/assets/derived/3/snapshot.bin',
                    $body,
                    $contentType,
                );
                self::fail('Expected invalid snapshot bytes.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset snapshot writes require non-empty PNG or WebP bytes.', $exception->getMessage());
            }
        }

        $failed = new MockHandler();
        $failed->append($this->awsFailure('PutObject', 500, 'InternalError'));
        $this->assertRuntimeMessage(
            fn () => $this->storage($failed)->putSnapshot(
                'organizations/99/assets/derived/4/snapshot.png',
                $png,
                'image/png',
            ),
            'Asset snapshot storage write failed.',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->storage(new MockHandler())->read('../unsafe.png');
    }

    public function testDefaultVideoProbeCleansTemporaryFilesOnEveryExitPath(): void
    {
        $jpeg = AssetTestFixtures::image('image/jpeg', 4, 3);
        $paths = [];
        $factory = static function () use (&$paths): ?string {
            $path = tempnam(sys_get_temp_dir(), 'vertoad-s3-probe-test-');
            if (!is_string($path)) {
                return null;
            }
            $paths[] = $path;

            return $path;
        };

        $inspection = $this->storage(
            $this->objectMock($jpeg, 'video/mp4'),
            temporaryPathFactory: $factory,
        )->inspect('organizations/99/assets/probe.mp4');
        self::assertSame([4, 3, null], [$inspection?->width, $inspection?->height, $inspection?->durationSeconds]);
        self::assertFileDoesNotExist($paths[0]);

        S3AssetStorageNative::$fileWriteMode = 'false';
        $writeFailure = $this->storage(
            $this->objectMock($jpeg, 'video/mp4'),
            temporaryPathFactory: $factory,
        )->inspect('organizations/99/assets/probe-write-failure.mp4');
        self::assertSame([0, 0, null], [$writeFailure?->width, $writeFailure?->height, $writeFailure?->durationSeconds]);
        self::assertFileDoesNotExist($paths[1]);

        S3AssetStorageNative::$fileWriteMode = 'throw';
        $analysisFailure = $this->storage(
            $this->objectMock($jpeg, 'video/mp4'),
            temporaryPathFactory: $factory,
        )->inspect('organizations/99/assets/probe-analysis-failure.mp4');
        self::assertSame([0, 0, null], [$analysisFailure?->width, $analysisFailure?->height, $analysisFailure?->durationSeconds]);
        self::assertFileDoesNotExist($paths[2]);

        S3AssetStorageNative::$fileWriteMode = 'normal';
        $noPath = $this->storage(
            $this->objectMock($jpeg, 'video/mp4'),
            temporaryPathFactory: static fn (): ?string => null,
        )->inspect('organizations/99/assets/probe-no-path.mp4');
        self::assertSame([0, 0, null], [$noPath?->width, $noPath?->height, $noPath?->durationSeconds]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'endpoint' => 'https://assets.example.test/',
            'region' => 'auto',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'max_read_bytes' => 1_024,
            'snapshot_cache_control' => 'public, max-age=31536000, immutable',
            'path_style_endpoint' => true,
        ], $overrides);
    }

    private function storage(
        MockHandler $mock,
        int $maxReadBytes = 1_024,
        ?string $encryption = null,
        ?callable $videoProbe = null,
        ?callable $temporaryPathFactory = null,
    ): S3AssetObjectStorage {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://assets.example.test',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
        ]);

        return $this->storageWithClient(
            $client,
            $maxReadBytes,
            $encryption,
            $videoProbe,
            $temporaryPathFactory,
        );
    }

    private function storageWithClient(
        S3Client $client,
        int $maxReadBytes = 1_024,
        ?string $encryption = null,
        ?callable $videoProbe = null,
        ?callable $temporaryPathFactory = null,
    ): S3AssetObjectStorage {
        $config = $this->config(['max_read_bytes' => $maxReadBytes]);
        if ($encryption !== null) {
            $config['server_side_encryption'] = $encryption;
        }

        return new S3AssetObjectStorage($config, $client, $videoProbe, $temporaryPathFactory);
    }

    private function objectMock(
        string $body,
        string $contentType,
        int|string|null $contentLength = null,
        ?string $encryption = null,
    ): MockHandler {
        $head = [
            'ContentLength' => $contentLength ?? strlen($body),
            'ContentType' => $contentType,
        ];
        if ($encryption !== null) {
            $head['ServerSideEncryption'] = $encryption;
        }
        $mock = new MockHandler();
        $mock->append(new Result($head));
        $mock->append(new Result(['Body' => Utils::streamFor($body)]));

        return $mock;
    }

    /** @param array<string, mixed> $config */
    private function assertInvalidConfiguration(array $config, string $message): void
    {
        try {
            new S3AssetObjectStorage($config);
            self::fail('Expected invalid asset S3 configuration.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function awsFailure(string $command, int $status, string $code): AwsException
    {
        return new AwsException($code, new Command($command), [
            'response' => new Response($status),
            'code' => $code,
        ]);
    }

    private function assertRuntimeMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected asset object storage failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final class S3AssetStorageNative
{
    public static string $fileWriteMode = 'normal';
}
}
