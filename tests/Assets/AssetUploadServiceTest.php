<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Assets\AssetRepository;
use VertoAD\Repository\Assets\AssetRepositoryInterface;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Assets\AssetValidationException;

final class AssetUploadServiceTest extends TestCase
{
    public function testCreateUploadIntentRejectsHtmlAndJavascriptUploads(): void
    {
        $service = $this->createService($this->createConnection());

        foreach ([
            ['creative.html', 'text/html'],
            ['creative.js', 'application/javascript'],
            ['creative.svg', 'image/svg+xml'],
        ] as [$filename, $contentType]) {
            try {
                $service->createUploadIntent(99, 7, 'image', $filename, $contentType, 100);
                self::fail('Expected validation exception for ' . $filename);
            } catch (AssetValidationException $exception) {
                self::assertSame('asset_type_not_allowed', $exception->errorCode);
            }
        }
    }

    public function testCreateUploadIntentRejectsMimeExtensionMismatchAndOversize(): void
    {
        $service = $this->createService($this->createConnection());

        $mismatch = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/jpeg', 100),
        );
        self::assertSame('asset_mime_extension_mismatch', $mismatch->errorCode);

        $oversize = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 10_485_761),
        );
        self::assertSame('asset_too_large', $oversize->errorCode);
    }

    public function testCreateUploadIntentRejectsUnknownTypeAndSupportsSnapshotAndTextLimits(): void
    {
        $service = $this->createService($this->createConnection());

        $unknown = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'html', 'creative.png', 'image/png', 100),
        );
        self::assertSame('asset_type_not_allowed', $unknown->errorCode);

        $snapshot = $service->createUploadIntent(99, 7, 'fabric_snapshot', 'creative.json', 'application/json', 512);
        self::assertSame('fabric_snapshot', $snapshot->type->value);

        $text = $service->createUploadIntent(99, 7, 'text', 'creative.txt', 'text/plain', 512);
        self::assertSame('text', $text->type->value);

        $oversizeSnapshot = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'fabric_snapshot', 'creative.json', 'application/json', 1_048_577),
        );
        self::assertSame('asset_too_large', $oversizeSnapshot->errorCode);
    }

    public function testCreateAndConfirmUseConfiguredUploadPolicy(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, AssetUploadPolicy::fromArray([
            'upload_intent_ttl_seconds' => 120,
            'blocked_extensions' => ['html'],
            'blocked_content_types' => ['text/html'],
            'types' => [
                'image' => [
                    'max_bytes' => 2048,
                    'max_width' => 512,
                    'max_height' => 512,
                    'allowed_content_types' => ['avif' => 'image/avif'],
                    'magic_signatures' => [
                        'image/avif' => [
                            ['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']],
                        ],
                    ],
                ],
                'video' => [
                    'max_bytes' => 4096,
                    'max_width' => 640,
                    'max_height' => 360,
                    'max_duration_seconds' => 15,
                    'allowed_content_types' => ['webm' => 'video/webm'],
                    'magic_signatures' => [
                        'video/webm' => [
                            ['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")],
                        ],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1024,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [
                            ['trimmed_prefix_ascii' => '{'],
                        ],
                    ],
                ],
                'text' => [
                    'max_bytes' => 512,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [
                            ['forbid_ascii_ci' => '<script'],
                        ],
                    ],
                ],
            ],
        ]), $inspector);

        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.avif', 'image/avif', 1024);
        $pngRejected = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024),
        );
        $this->storeObject($inspector, $intent, "\x00\x00\x00\x18ftypavif", width: 513, height: 512);
        $tooWide = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/avif',
            1024,
        ));
        $this->storeObject($inspector, $intent, "\x00\x00\x00\x18ftypavif", width: 512, height: 512);
        $asset = $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/avif',
            1024,
            null,
        );

        self::assertSame('image/avif', $intent->contentType);
        self::assertSame('asset_mime_extension_mismatch', $pngRejected->errorCode);
        self::assertSame('asset_dimensions_out_of_bounds', $tooWide->errorCode);
        self::assertSame('image/avif', $asset->contentType);
        self::assertGreaterThan(new \DateTimeImmutable('+90 seconds'), $intent->expiresAt);
        self::assertLessThan(new \DateTimeImmutable('+130 seconds'), $intent->expiresAt);
    }

    public function testConfirmRejectsMagicMismatchAndDimensionBoundaries(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);

        $this->storeObject($inspector, $intent, "\xFF\xD8\xFF\xE0jpeg");
        $magicMismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_magic_mismatch', $magicMismatch->errorCode);

        $this->storeObject($inspector, $intent, '');
        $missingMagic = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_magic_mismatch', $missingMagic->errorCode);
        self::assertSame('Magic bytes are required.', $missingMagic->getMessage());

        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", width: 4097, height: 600);
        $tooWide = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_dimensions_out_of_bounds', $tooWide->errorCode);

        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", width: 0, height: 600);
        $zeroWidth = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_dimensions_out_of_bounds', $zeroWidth->errorCode);
    }

    public function testConfirmRejectsForgedClientMagicWhenStoredObjectIsUnsafe(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);
        $this->storeObject($inspector, $intent, '<script>alert(1)</script>');

        $rejected = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));

        self::assertSame('asset_magic_mismatch', $rejected->errorCode);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM creative_assets'));
    }

    public function testConfirmRejectsStoredObjectMetadataMismatch(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);

        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", contentType: 'image/jpeg');
        $contentTypeMismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_uploaded_object_mismatch', $contentTypeMismatch->errorCode);

        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", byteSize: 2048);
        $byteSizeMismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_uploaded_object_mismatch', $byteSizeMismatch->errorCode);

        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", checksum: 'sha256:stored');
        $checksumMismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
            'sha256:client',
        ));
        self::assertSame('asset_uploaded_object_mismatch', $checksumMismatch->errorCode);
        self::assertSame('Stored object checksum does not match the confirmation payload.', $checksumMismatch->getMessage());
    }

    public function testConfirmRejectsVideoDurationAndResolutionBoundaries(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'video', 'creative.mp4', 'video/mp4', 5_000_000);
        $mp4Header = "\x00\x00\x00\x18ftypmp42";

        $this->storeObject($inspector, $intent, $mp4Header, width: 1920, height: 1080, durationSeconds: 120.1);
        $tooLong = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'video/mp4',
            5_000_000,
        ));
        self::assertSame('asset_duration_out_of_bounds', $tooLong->errorCode);

        $this->storeObject($inspector, $intent, $mp4Header, width: 3840, height: 2161, durationSeconds: 30.0);
        $tooTall = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'video/mp4',
            5_000_000,
        ));
        self::assertSame('asset_video_resolution_out_of_bounds', $tooTall->errorCode);
    }

    public function testSuccessfulConfirmCreatesPendingReviewAssetAndSnapshotJob(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);
        $this->storeObject($inspector, $intent, "\x89PNG\r\n\x1A\npayload", checksum: 'sha256:abc');

        $asset = $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
            'sha256:abc',
        );

        self::assertNotNull($asset->id);
        self::assertSame(99, $asset->organizationId);
        self::assertSame(7, $asset->uploaderUserId);
        self::assertSame('image', $asset->type->value);
        self::assertSame('pending_review', $asset->status->value);
        self::assertSame($intent->objectKey, $asset->objectKey);

        $jobs = $connection->fetchAllAssociative('SELECT * FROM asset_snapshot_jobs');
        self::assertCount(1, $jobs);
        self::assertSame((string) $asset->id, (string) $jobs[0]['asset_id']);
        self::assertSame('pending', $jobs[0]['status']);
    }

    public function testConfirmRejectsExpiredUploadIntentWithoutCreatingAsset(): void
    {
        $connection = $this->createConnection();
        $repository = new AssetRepository($connection);
        $intent = $repository->createUploadIntent(new AssetUploadIntent(
            id: null,
            organizationId: 99,
            uploaderUserId: 7,
            type: AssetType::Image,
            originalFilename: 'creative.png',
            objectKey: 'organizations/99/assets/expired-token.png',
            contentType: 'image/png',
            byteSize: 1024,
            status: AssetStatus::PendingUpload,
            expiresAt: new \DateTimeImmutable('-1 minute'),
        ));
        $service = $this->createService($connection);

        $expired = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
            null,
        ));

        self::assertSame('asset_upload_intent_expired', $expired->errorCode);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM creative_assets'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_snapshot_jobs'));
    }

    public function testConfirmRejectsMetadataMismatchAndMissingStoredObject(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);

        $metadataMismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey . '-wrong',
            'image/png',
            1024,
        ));
        self::assertSame('asset_upload_metadata_mismatch', $metadataMismatch->errorCode);

        $missingObject = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_uploaded_object_not_found', $missingObject->errorCode);
    }

    public function testConfirmAcceptsSupportedMagicFamilies(): void
    {
        $cases = [
            ['image', 'creative.jpg', 'image/jpeg', "\xFF\xD8\xFF\xE0jpeg", null],
            ['image', 'creative.gif', 'image/gif', 'GIF89apayload', null],
            ['image', 'creative.webp', 'image/webp', 'RIFFxxxxWEBPpayload', null],
            ['video', 'creative.webm', 'video/webm', "\x1A\x45\xDF\xA3payload", 30.0],
            ['fabric_snapshot', 'creative.json', 'application/json', ' {"objects":[]}', null],
            ['text', 'creative.txt', 'text/plain', 'plain copy', null],
        ];

        foreach ($cases as [$type, $filename, $contentType, $magic, $duration]) {
            $connection = $this->createConnection();
            $inspector = new InMemoryObjectStorageInspector();
            $service = $this->createService($connection, inspector: $inspector);
            $intent = $service->createUploadIntent(99, 7, $type, $filename, $contentType, 1024);
            $this->storeObject($inspector, $intent, $magic, durationSeconds: $duration);

            $asset = $service->confirmUploadedAsset(
                99,
                7,
                $intent->id,
                $intent->objectKey,
                $contentType,
                1024,
            );

            self::assertSame($type, $asset->type->value);
            self::assertSame('pending_review', $asset->status->value);
        }
    }

    public function testConfirmAcceptsMp4MagicAndRejectsUnsupportedIntentContentType(): void
    {
        $connection = $this->createConnection();
        $inspector = new InMemoryObjectStorageInspector();
        $service = $this->createService($connection, inspector: $inspector);
        $intent = $service->createUploadIntent(99, 7, 'video', 'creative.mp4', 'video/mp4', 1024);
        $this->storeObject($inspector, $intent, "\x00\x00\x00\x18ftypmp42", width: 640, height: 360, durationSeconds: 30.0);

        $asset = $service->confirmUploadedAsset(
            99,
            7,
            $intent->id,
            $intent->objectKey,
            'video/mp4',
            1024,
        );
        self::assertSame('video', $asset->type->value);

        $unsupportedInspector = new InMemoryObjectStorageInspector([
            new StoredObjectInspection(
                objectKey: 'organizations/99/assets/creative.bin',
                contentType: 'application/octet-stream',
                byteSize: 10,
                width: 1,
                height: 1,
                durationSeconds: null,
                checksum: 'sha256:' . hash('sha256', 'binary'),
                leadingBytes: 'binary',
            ),
        ]);
        $unsupported = new AssetUploadService(
            new class implements AssetRepositoryInterface {
                public function createUploadIntent(AssetUploadIntent $intent): AssetUploadIntent
                {
                    return $intent;
                }

                public function findUploadIntentForConfirmation(int $id, int $organizationId, int $uploaderUserId): ?AssetUploadIntent
                {
                    return new AssetUploadIntent(
                        id: $id,
                        organizationId: $organizationId,
                        uploaderUserId: $uploaderUserId,
                        type: AssetType::Text,
                        originalFilename: 'creative.bin',
                        objectKey: 'organizations/99/assets/creative.bin',
                        contentType: 'application/octet-stream',
                        byteSize: 10,
                        status: AssetStatus::PendingUpload,
                        expiresAt: new \DateTimeImmutable('+1 hour'),
                    );
                }

                public function createAssetWithSnapshotJob(CreativeAsset $asset): CreativeAsset
                {
                    return $asset;
                }
            },
            new DeterministicPresignedUploadSigner([
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'path_style_endpoint' => true,
            ]),
            $unsupportedInspector,
        );

        $exception = $this->captureValidation(fn () => $unsupported->confirmUploadedAsset(
            99,
            7,
            1,
            'organizations/99/assets/creative.bin',
            'application/octet-stream',
            10,
        ));
        self::assertSame('asset_magic_mismatch', $exception->errorCode);
    }

    public function testConfirmRequiresMatchingOrganizationAndIntentOwner(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $intent = $service->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 1024);

        $wrongOrg = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            100,
            7,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_upload_intent_not_found', $wrongOrg->errorCode);

        $wrongUser = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            99,
            8,
            $intent->id,
            $intent->objectKey,
            'image/png',
            1024,
        ));
        self::assertSame('asset_upload_intent_not_found', $wrongUser->errorCode);
    }

    private function captureValidation(callable $callback): AssetValidationException
    {
        try {
            $callback();
        } catch (AssetValidationException $exception) {
            return $exception;
        }

        self::fail('Expected asset validation exception.');
    }

    private function createService(
        Connection $connection,
        ?AssetUploadPolicy $policy = null,
        ?InMemoryObjectStorageInspector $inspector = null,
    ): AssetUploadService
    {
        return new AssetUploadService(
            new AssetRepository($connection),
            new DeterministicPresignedUploadSigner([
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'path_style_endpoint' => true,
            ]),
            $inspector ?? new InMemoryObjectStorageInspector(),
            $policy ?? AssetUploadPolicy::default(),
            static fn (): string => 'fixed-token',
        );
    }

    private function storeObject(
        InMemoryObjectStorageInspector $inspector,
        AssetUploadIntent $intent,
        string $leadingBytes,
        int $width = 800,
        int $height = 600,
        ?float $durationSeconds = null,
        ?string $contentType = null,
        ?int $byteSize = null,
        ?string $checksum = null,
    ): void {
        $inspector->put(new StoredObjectInspection(
            objectKey: $intent->objectKey,
            contentType: $contentType ?? $intent->contentType,
            byteSize: $byteSize ?? $intent->byteSize,
            width: $width,
            height: $height,
            durationSeconds: $durationSeconds,
            checksum: $checksum ?? 'sha256:' . hash('sha256', $leadingBytes),
            leadingBytes: $leadingBytes,
        ));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        AssetSchema::create($connection);

        return $connection;
    }
}
