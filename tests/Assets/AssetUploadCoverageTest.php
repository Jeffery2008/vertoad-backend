<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageAssetFinalizerInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Assets\AssetRepositoryInterface;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Assets\AssetValidationException;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;

final class AssetUploadCoverageTest extends TestCase
{
    public function testRejectsInvalidIdentifiersUnsafeFilenameAndGeneratedToken(): void
    {
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $service = $this->service($repository, tokenGenerator: static fn (): string => 'fixed-token');

        $invalidOwner = $this->captureValidation(
            fn () => $service->createUploadIntent(0, 7, 'image', 'creative.png', 'image/png', 100),
        );
        $unsafeFilename = $this->captureValidation(
            fn () => $service->createUploadIntent(99, 7, 'image', 'nested/creative.png', 'image/png', 100),
        );
        $invalidConfirmationOwner = $this->captureValidation(
            fn () => $service->confirmUploadedAsset(99, 7, 0, 'organizations/99/assets/a.png', 'image/png', 100),
        );
        $invalidToken = $this->captureValidation(
            fn () => $this->service($repository, tokenGenerator: static fn (): string => 'short')
                ->createUploadIntent(99, 7, 'image', 'creative.png', 'image/png', 100),
        );

        self::assertSame('asset_owner_invalid', $invalidOwner->errorCode);
        self::assertSame('asset_filename_invalid', $unsafeFilename->errorCode);
        self::assertSame('asset_owner_invalid', $invalidConfirmationOwner->errorCode);
        self::assertSame('asset_object_key_generation_failed', $invalidToken->errorCode);
        self::assertSame(500, $invalidToken->status);
    }

    public function testConfirmedUploadRetryReturnsExistingAssetAndRejectsChangedChecksum(): void
    {
        $body = AssetTestFixtures::image();
        $existing = $this->confirmedAsset($body);
        $stagingObjectKey = $this->stagingKeyForAsset($existing);
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn(null);
        $repository->method('findAssetByUploadIntent')->willReturn($existing);
        $storage = new InMemoryObjectStorageInspector([$this->inspectionForAsset($existing, $body)]);
        $service = $this->service($repository, $storage);
        $matchingChecksum = strtoupper(substr((string) $existing->checksum, strlen('sha256:')));

        $retriedWithoutPrefix = $service->confirmUploadedAsset(
            $existing->organizationId,
            $existing->uploaderUserId,
            $existing->uploadIntentId,
            $stagingObjectKey,
            ' IMAGE/PNG ',
            $existing->byteSize,
            $matchingChecksum,
        );
        $retriedWithPrefix = $service->confirmUploadedAsset(
            $existing->organizationId,
            $existing->uploaderUserId,
            $existing->uploadIntentId,
            $stagingObjectKey,
            $existing->contentType,
            $existing->byteSize,
            'sha256:' . $matchingChecksum,
        );
        $mismatch = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            $existing->organizationId,
            $existing->uploaderUserId,
            $existing->uploadIntentId,
            $stagingObjectKey,
            $existing->contentType,
            $existing->byteSize,
            'sha256:' . str_repeat('f', 64),
        ));

        self::assertSame($existing, $retriedWithoutPrefix);
        self::assertSame($existing, $retriedWithPrefix);
        self::assertSame('asset_upload_metadata_mismatch', $mismatch->errorCode);
    }

    public function testConfirmedUploadRetryRejectsPersistedAssetWithoutCanonicalChecksum(): void
    {
        foreach ([null, 'not-a-checksum', str_repeat('a', 64), 'sha256:' . str_repeat('A', 64)] as $checksum) {
            $existing = $this->assetWithChecksum($checksum);
            $repository = $this->createStub(AssetRepositoryInterface::class);
            $repository->method('findUploadIntentForConfirmation')->willReturn(null);
            $repository->method('findAssetByUploadIntent')->willReturn($existing);

            $exception = $this->captureValidation(fn () => $this->service($repository)->confirmUploadedAsset(
                $existing->organizationId,
                $existing->uploaderUserId,
                $existing->uploadIntentId,
                $this->stagingKeyForAsset($existing),
                $existing->contentType,
                $existing->byteSize,
            ));

            self::assertSame('asset_uploaded_checksum_unavailable', $exception->errorCode);
            self::assertSame(503, $exception->status);
            self::assertSame('Confirmed asset does not contain an authoritative checksum.', $exception->getMessage());
        }
    }

    public function testConfirmedUploadRetryRejectsFinalKeyWithDifferentEmbeddedChecksum(): void
    {
        $body = AssetTestFixtures::image();
        $asset = $this->confirmedAsset($body);
        $mismatchedKey = preg_replace(
            '/-sha256-[a-f0-9]{64}\./D',
            '-sha256-' . str_repeat('f', 64) . '.',
            $asset->objectKey,
        );
        self::assertIsString($mismatchedKey);
        self::assertNotSame($asset->objectKey, $mismatchedKey);
        $mismatchedAsset = new CreativeAsset(
            id: $asset->id,
            uploadIntentId: $asset->uploadIntentId,
            organizationId: $asset->organizationId,
            uploaderUserId: $asset->uploaderUserId,
            type: $asset->type,
            objectKey: $mismatchedKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: $asset->durationSeconds,
            checksum: $asset->checksum,
            status: $asset->status,
        );
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn(null);
        $repository->method('findAssetByUploadIntent')->willReturn($mismatchedAsset);

        $exception = $this->captureValidation(fn () => $this->service($repository)->confirmUploadedAsset(
            $mismatchedAsset->organizationId,
            $mismatchedAsset->uploaderUserId,
            $mismatchedAsset->uploadIntentId,
            $this->stagingKeyForAsset($mismatchedAsset),
            $mismatchedAsset->contentType,
            $mismatchedAsset->byteSize,
            $mismatchedAsset->checksum,
        ));

        self::assertSame('asset_upload_metadata_mismatch', $exception->errorCode);
        self::assertSame('Uploaded asset metadata does not match the already confirmed asset.', $exception->getMessage());
    }

    public function testConfirmedRetryReportsClientMetadataMismatchBeforeCorruptPersistedChecksum(): void
    {
        $existing = $this->assetWithChecksum(null);
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn(null);
        $repository->method('findAssetByUploadIntent')->willReturn($existing);
        $service = $this->service($repository);

        $exception = $this->captureValidation(fn () => $service->confirmUploadedAsset(
            $existing->organizationId,
            $existing->uploaderUserId,
            $existing->uploadIntentId,
            $this->stagingKeyForAsset($existing) . '.changed',
            $existing->contentType,
            $existing->byteSize,
        ));

        self::assertSame('asset_upload_metadata_mismatch', $exception->errorCode);
        self::assertSame(422, $exception->status);
    }

    public function testRejectsMalformedChecksumDuringFirstConfirmationWithSameContractAsRetry(): void
    {
        $intent = $this->intent(AssetType::Image, 100);
        $firstService = $this->service($this->repositoryReturning($intent));
        $first = $this->captureValidation(fn () => $this->confirm(
            $firstService,
            $intent,
            'checksum-with-invalid-format',
        ));

        $existing = $this->confirmedAsset(AssetTestFixtures::image());
        $retryRepository = $this->createStub(AssetRepositoryInterface::class);
        $retryRepository->method('findUploadIntentForConfirmation')->willReturn(null);
        $retryRepository->method('findAssetByUploadIntent')->willReturn($existing);
        $retryService = $this->service($retryRepository);
        $retry = $this->captureValidation(fn () => $this->confirm(
            $retryService,
            new AssetUploadIntent(
                id: $existing->uploadIntentId,
                organizationId: $existing->organizationId,
                uploaderUserId: $existing->uploaderUserId,
                type: $existing->type,
                originalFilename: 'creative.png',
                objectKey: $this->stagingKeyForAsset($existing),
                contentType: $existing->contentType,
                byteSize: $existing->byteSize,
                status: AssetStatus::PendingUpload,
                expiresAt: new \DateTimeImmutable('+1 hour'),
            ),
            'checksum-with-invalid-format',
        ));

        self::assertSame('asset_upload_metadata_mismatch', $first->errorCode);
        self::assertSame(422, $first->status);
        self::assertSame($first->errorCode, $retry->errorCode);
        self::assertSame($first->status, $retry->status);
        self::assertSame($first->getMessage(), $retry->getMessage());
    }

    public function testTreatsNullAndBlankChecksumsAsNotProvidedOnFirstConfirmation(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $asset = $this->assetFor($intent, $body, 73);
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn($intent);
        $repository->method('createAssetWithSnapshotJob')->willReturn($asset);
        $service = $this->service(
            $repository,
            $this->inspectorReturning($this->inspection(
                $intent,
                checksum: (string) $asset->checksum,
                body: $body,
                leadingBytes: substr($body, 0, 512),
            )),
        );

        $withoutChecksum = $this->confirm($service, $intent);
        $withEmptyChecksum = $this->confirm($service, $intent, '');
        $withWhitespaceChecksum = $this->confirm($service, $intent, '   ');

        self::assertSame($asset, $withoutChecksum);
        self::assertSame($asset, $withEmptyChecksum);
        self::assertSame($asset, $withWhitespaceChecksum);
    }

    public function testRejectsMalformedAuthoritativeChecksumAndMismatchedStoredBody(): void
    {
        $intent = $this->intent(AssetType::Image, 100);
        $repository = $this->repositoryReturning($intent);
        $inspector = $this->createStub(ObjectStorageInspectorInterface::class);
        $inspector->method('inspect')->willReturnOnConsecutiveCalls(
            $this->inspection($intent, checksum: null, body: null, leadingBytes: "\x89PNG\r\n\x1A\n"),
            $this->inspection($intent, checksum: 'not-a-checksum', body: null, leadingBytes: "\x89PNG\r\n\x1A\n"),
            $this->inspection(
                $intent,
                checksum: 'sha256:' . hash('sha256', 'short'),
                body: 'short',
                leadingBytes: "\x89PNG\r\n\x1A\n",
            ),
        );
        $service = $this->service($repository, $inspector);

        $missingChecksum = $this->captureValidation(fn () => $this->confirm($service, $intent));
        $checksumUnavailable = $this->captureValidation(fn () => $this->confirm($service, $intent));
        $bodyMismatch = $this->captureValidation(fn () => $this->confirm($service, $intent));

        self::assertSame('asset_uploaded_checksum_unavailable', $missingChecksum->errorCode);
        self::assertSame(503, $missingChecksum->status);
        self::assertSame('asset_uploaded_checksum_unavailable', $checksumUnavailable->errorCode);
        self::assertSame(503, $checksumUnavailable->status);
        self::assertSame('asset_uploaded_object_mismatch', $bodyMismatch->errorCode);
        self::assertSame('Stored object bytes do not match authoritative metadata.', $bodyMismatch->getMessage());
    }

    public function testRejectsFabricWithoutBodyAndDimensionsBeyondImagePolicy(): void
    {
        $missingBodyIntent = $this->intent(AssetType::FabricSnapshot, 100);
        $missingBodyInspector = $this->inspectorReturning($this->inspection(
            $missingBodyIntent,
            checksum: 'sha256:' . str_repeat('a', 64),
            body: null,
            leadingBytes: '{',
        ));
        $missingBodyService = $this->service($this->repositoryReturning($missingBodyIntent), $missingBodyInspector);

        $missingBody = $this->captureValidation(fn () => $this->confirm($missingBodyService, $missingBodyIntent));

        $snapshot = AssetTestFixtures::dataUrl('image/png', AssetTestFixtures::image('image/png', 4097, 1));
        $body = AssetTestFixtures::fabric(snapshot: $snapshot);
        $oversizedIntent = $this->intent(AssetType::FabricSnapshot, strlen($body));
        $oversizedInspector = $this->inspectorReturning($this->inspection(
            $oversizedIntent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        ));
        $oversizedService = $this->service(
            $this->repositoryReturning($oversizedIntent),
            $oversizedInspector,
            fabricValidator: new FabricCreativePayloadValidator(maxWidth: 5000),
        );

        $oversized = $this->captureValidation(fn () => $this->confirm($oversizedService, $oversizedIntent));

        self::assertSame('asset_fabric_payload_unavailable', $missingBody->errorCode);
        self::assertSame('asset_dimensions_out_of_bounds', $oversized->errorCode);
        self::assertSame('Fabric snapshot dimensions exceed allowed bounds.', $oversized->getMessage());
    }

    public function testRejectsMetadataOnlyInspectionWithoutMagicBytes(): void
    {
        $intent = $this->intent(AssetType::Image, 100);
        $inspection = $this->inspection(
            $intent,
            checksum: 'sha256:' . str_repeat('a', 64),
            body: null,
            leadingBytes: '',
        );
        $service = $this->service($this->repositoryReturning($intent), $this->inspectorReturning($inspection));

        $exception = $this->captureValidation(fn () => $this->confirm($service, $intent));

        self::assertSame('asset_magic_mismatch', $exception->errorCode);
        self::assertSame('Magic bytes are required.', $exception->getMessage());
    }

    public function testReturnsConcurrentWinnerAfterUniqueConstraintRace(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $winner = $this->assetFor($intent, $body, 73);
        $race = new SyntheticAssetUniqueConstraintViolationException();
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn($intent);
        $repository->method('findAssetByUploadIntent')->willReturn($winner);
        $repository->method('createAssetWithSnapshotJob')->willThrowException($race);
        $service = $this->service(
            $repository,
            $this->inspectorReturning($this->inspection(
                $intent,
                checksum: (string) $winner->checksum,
                body: $body,
                leadingBytes: substr($body, 0, 512),
                width: $winner->width,
                height: $winner->height,
            )),
        );

        $asset = $this->confirm($service, $intent);

        self::assertSame($winner, $asset);
    }

    public function testRejectsConcurrentWinnerThatConfirmedDifferentObjectBytes(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $winnerBody = AssetTestFixtures::image(width: 5, height: 3);
        $winner = $this->assetFor($intent, $winnerBody, 73, 'coverage-final-token');
        $finalizer = new ScriptedAssetFinalizer([$this->inspectionForAsset($winner, $winnerBody)]);
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn($intent);
        $repository->method('findAssetByUploadIntent')->willReturn($winner);
        $repository->method('createAssetWithSnapshotJob')->willThrowException(
            new SyntheticAssetUniqueConstraintViolationException(),
        );
        $service = $this->service(
            $repository,
            $this->inspectorReturning($this->inspection(
                $intent,
                checksum: 'sha256:' . hash('sha256', $body),
                body: $body,
                leadingBytes: substr($body, 0, 512),
            )),
            finalizer: $finalizer,
        );

        $exception = $this->captureValidation(fn () => $this->confirm($service, $intent));

        self::assertSame('asset_upload_metadata_mismatch', $exception->errorCode);
        self::assertSame('Uploaded asset metadata does not match the already confirmed asset.', $exception->getMessage());
        $candidateKey = 'organizations/99/assets/final/coverage-token/coverage-final-token-sha256-'
            . hash('sha256', $body) . '.png';
        self::assertNotSame($winner->objectKey, $candidateKey);
        self::assertNull($finalizer->inspect($candidateKey));
        self::assertNotNull($finalizer->inspect($winner->objectKey));
        self::assertContains($candidateKey, $finalizer->deletedObjectKeys);
    }

    public function testRethrowsUniqueConstraintWhenConcurrentWinnerCannotBeFound(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $race = new SyntheticAssetUniqueConstraintViolationException();
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn($intent);
        $repository->method('findAssetByUploadIntent')->willReturn(null);
        $repository->method('createAssetWithSnapshotJob')->willThrowException($race);
        $service = $this->service(
            $repository,
            $this->inspectorReturning($this->inspection(
                $intent,
                checksum: 'sha256:' . hash('sha256', $body),
                body: $body,
                leadingBytes: substr($body, 0, 512),
            )),
        );

        try {
            $this->confirm($service, $intent);
            self::fail('Expected the unique constraint violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertSame($race, $exception);
        }
    }

    public function testFailsClosedWithoutFinalizerAfterValidatingUploadedBytes(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $repository = $this->repositoryReturning($intent);
        $inspector = $this->inspectorReturning($this->inspection(
            $intent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        ));
        $service = new AssetUploadService(
            $repository,
            $this->createStub(ObjectStorageUploadSignerInterface::class),
            $inspector,
        );

        $exception = $this->captureValidation(fn () => $this->confirm($service, $intent));

        self::assertSame('asset_storage_finalization_unavailable', $exception->errorCode);
        self::assertSame(503, $exception->status);
    }

    public function testTreatsRepositoryExceptionWithVisibleAssetAsAmbiguousSuccessfulCommit(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $storage = new InMemoryObjectStorageInspector([$this->inspection(
            $intent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        )]);
        $repository = new class($intent) implements AssetRepositoryInterface {
            private ?CreativeAsset $committed = null;

            public function __construct(private readonly AssetUploadIntent $intent)
            {
            }

            public function createUploadIntent(AssetUploadIntent $intent): AssetUploadIntent
            {
                return $intent;
            }

            public function findUploadIntentForConfirmation(
                int $id,
                int $organizationId,
                int $uploaderUserId,
            ): ?AssetUploadIntent {
                return $this->intent;
            }

            public function findAssetByUploadIntent(
                int $uploadIntentId,
                int $organizationId,
                int $uploaderUserId,
            ): ?CreativeAsset {
                return $this->committed;
            }

            public function createAssetWithSnapshotJob(CreativeAsset $asset): CreativeAsset
            {
                $this->committed = new CreativeAsset(
                    id: 73,
                    uploadIntentId: $asset->uploadIntentId,
                    organizationId: $asset->organizationId,
                    uploaderUserId: $asset->uploaderUserId,
                    type: $asset->type,
                    objectKey: $asset->objectKey,
                    contentType: $asset->contentType,
                    byteSize: $asset->byteSize,
                    width: $asset->width,
                    height: $asset->height,
                    durationSeconds: $asset->durationSeconds,
                    checksum: $asset->checksum,
                    status: $asset->status,
                );

                throw new \RuntimeException('Synthetic ambiguous database commit.');
            }
        };
        $service = new AssetUploadService(
            $repository,
            $this->createStub(ObjectStorageUploadSignerInterface::class),
            $storage,
            finalTokenGenerator: static fn (): string => 'ambiguous-final-token',
            finalizer: $storage,
        );

        $asset = $this->confirm($service, $intent);

        self::assertSame(73, $asset->id);
        self::assertSame($body, $storage->inspect($asset->objectKey)?->body);
        self::assertNull($storage->inspect($intent->objectKey));
    }

    public function testFinalizationRejectsMissingBodyInvalidKeysTokensAndProviderFailure(): void
    {
        $missingBodyIntent = $this->intent(AssetType::Image, 100);
        $missingBody = $this->captureValidation(fn () => $this->confirm(
            $this->service(
                $this->repositoryReturning($missingBodyIntent),
                $this->inspectorReturning($this->inspection(
                    $missingBodyIntent,
                    checksum: 'sha256:' . str_repeat('a', 64),
                    body: null,
                    leadingBytes: "\x89PNG\r\n\x1A\n",
                )),
            ),
            $missingBodyIntent,
        ));
        self::assertSame('asset_uploaded_body_unavailable', $missingBody->errorCode);
        self::assertSame(503, $missingBody->status);

        $body = AssetTestFixtures::image();
        $invalidKeyIntent = new AssetUploadIntent(
            id: 41,
            organizationId: 99,
            uploaderUserId: 7,
            type: AssetType::Image,
            originalFilename: 'creative.png',
            objectKey: 'organizations/99/assets/coverage-token.png',
            contentType: 'image/png',
            byteSize: strlen($body),
            status: AssetStatus::PendingUpload,
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
        $invalidKey = $this->captureValidation(fn () => $this->confirm(
            $this->service(
                $this->repositoryReturning($invalidKeyIntent),
                $this->inspectorReturning($this->inspection(
                    $invalidKeyIntent,
                    checksum: 'sha256:' . hash('sha256', $body),
                    body: $body,
                    leadingBytes: substr($body, 0, 512),
                )),
            ),
            $invalidKeyIntent,
        ));
        self::assertSame('asset_object_key_generation_failed', $invalidKey->errorCode);
        self::assertSame('Asset staging object key is invalid.', $invalidKey->getMessage());

        $intent = $this->intent(AssetType::Image, strlen($body));
        $inspection = $this->inspection(
            $intent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        );
        $invalidToken = $this->captureValidation(fn () => $this->confirm(
            $this->service(
                $this->repositoryReturning($intent),
                $this->inspectorReturning($inspection),
                finalTokenGenerator: static fn (): string => 'short',
            ),
            $intent,
        ));
        self::assertSame('asset_object_key_generation_failed', $invalidToken->errorCode);
        self::assertSame('Asset final object key token generation failed.', $invalidToken->getMessage());

        $finalizer = new ScriptedAssetFinalizer();
        $finalizer->copyFailure = new \RuntimeException('Synthetic final write failure.');
        $promotion = $this->captureValidation(fn () => $this->confirm(
            $this->service(
                $this->repositoryReturning($intent),
                $this->inspectorReturning($inspection),
                finalizer: $finalizer,
            ),
            $intent,
        ));
        self::assertSame('asset_storage_promotion_failed', $promotion->errorCode);
        self::assertSame(503, $promotion->status);
        self::assertSame('Synthetic final write failure.', $promotion->getPrevious()?->getMessage());
    }

    public function testRepositoryFailuresPreserveUnknownCommitAndCleanKnownRollbackOrLosingFinal(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $inspection = $this->inspection(
            $intent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        );
        $candidateKey = 'organizations/99/assets/final/coverage-token/coverage-final-token-sha256-'
            . hash('sha256', $body) . '.png';

        $lookupFailureRepository = new ScriptedAssetRepository($intent);
        $lookupFailureRepository->createFailure = new \RuntimeException('Synthetic database write failure.');
        $lookupFailureRepository->lookupFailure = new \RuntimeException('Synthetic commit lookup failure.');
        $unknownFinalizer = new ScriptedAssetFinalizer();
        try {
            $this->confirm($this->service(
                $lookupFailureRepository,
                $this->inspectorReturning($inspection),
                finalizer: $unknownFinalizer,
            ), $intent);
            self::fail('Expected ambiguous database failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic database write failure.', $exception->getMessage());
        }
        self::assertNotNull($unknownFinalizer->inspect($candidateKey));

        $rollbackRepository = new ScriptedAssetRepository($intent);
        $rollbackRepository->createFailure = new \RuntimeException('Synthetic rolled back write.');
        $rollbackFinalizer = new ScriptedAssetFinalizer();
        try {
            $this->confirm($this->service(
                $rollbackRepository,
                $this->inspectorReturning($inspection),
                finalizer: $rollbackFinalizer,
            ), $intent);
            self::fail('Expected rolled back database failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic rolled back write.', $exception->getMessage());
        }
        self::assertNull($rollbackFinalizer->inspect($candidateKey));
        self::assertContains($candidateKey, $rollbackFinalizer->deletedObjectKeys);

        $winner = $this->assetFor($intent, $body, 91);
        $winnerRepository = new ScriptedAssetRepository($intent);
        $winnerRepository->createFailure = new \RuntimeException('Synthetic post-commit failure.');
        $winnerRepository->existing = $winner;
        $winnerFinalizer = new ScriptedAssetFinalizer([$this->inspectionForAsset($winner, $body)]);
        $returned = $this->confirm($this->service(
            $winnerRepository,
            $this->inspectorReturning($inspection),
            finalizer: $winnerFinalizer,
        ), $intent);
        self::assertSame($winner, $returned);
        self::assertNull($winnerFinalizer->inspect($candidateKey));
        self::assertNotNull($winnerFinalizer->inspect($winner->objectKey));
    }

    public function testExistingFinalVerificationAndRecoveryFailuresAreExplicit(): void
    {
        $body = AssetTestFixtures::image();
        $asset = $this->confirmedAsset($body);
        $stagingKey = $this->stagingKeyForAsset($asset);
        $staging = new StoredObjectInspection(
            objectKey: $stagingKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: null,
            checksum: $asset->checksum,
            leadingBytes: substr($body, 0, 512),
            body: $body,
        );
        $repository = new ScriptedAssetRepository(null);
        $repository->existing = $asset;

        $inspectFailure = new ScriptedAssetFinalizer();
        $inspectFailure->inspectFailure = new \RuntimeException('Synthetic final read failure.');
        $verification = $this->captureValidation(fn () => $this->confirmExisting(
            $this->service($repository, $this->inspectorReturning($staging), finalizer: $inspectFailure),
            $asset,
            $stagingKey,
        ));
        self::assertSame('asset_storage_final_verification_failed', $verification->errorCode);
        self::assertSame('Synthetic final read failure.', $verification->getPrevious()?->getMessage());

        $throwingInspector = new class implements ObjectStorageInspectorInterface {
            public function inspect(string $objectKey): ?StoredObjectInspection
            {
                throw new \RuntimeException('Synthetic staging read failure.');
            }
        };
        $sourceFailure = $this->captureValidation(fn () => $this->confirmExisting(
            $this->service($repository, $throwingInspector, finalizer: new ScriptedAssetFinalizer()),
            $asset,
            $stagingKey,
        ));
        self::assertSame('asset_storage_final_verification_failed', $sourceFailure->errorCode);
        self::assertSame('Synthetic staging read failure.', $sourceFailure->getPrevious()?->getMessage());

        $validationFailure = new ScriptedAssetFinalizer();
        $validationFailure->copyFailure = new AssetValidationException('synthetic_recovery_rejected', 'Rejected recovery.', 503);
        $rejected = $this->captureValidation(fn () => $this->confirmExisting(
            $this->service($repository, $this->inspectorReturning($staging), finalizer: $validationFailure),
            $asset,
            $stagingKey,
        ));
        self::assertSame('synthetic_recovery_rejected', $rejected->errorCode);

        $providerFailure = new ScriptedAssetFinalizer();
        $providerFailure->copyFailure = new \RuntimeException('Synthetic recovery write failure.');
        $recovery = $this->captureValidation(fn () => $this->confirmExisting(
            $this->service($repository, $this->inspectorReturning($staging), finalizer: $providerFailure),
            $asset,
            $stagingKey,
        ));
        self::assertSame('asset_storage_final_verification_failed', $recovery->errorCode);
        self::assertSame('Synthetic recovery write failure.', $recovery->getPrevious()?->getMessage());
    }

    public function testStreamedFinalMetadataMayOmitBodyButRecoverySourceMayNot(): void
    {
        $body = AssetTestFixtures::image();
        $asset = $this->confirmedAsset($body);
        $stagingKey = $this->stagingKeyForAsset($asset);
        $metadataOnlyFinal = new StoredObjectInspection(
            objectKey: $asset->objectKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: null,
            checksum: $asset->checksum,
            leadingBytes: substr($body, 0, 512),
            body: null,
        );
        $repository = new ScriptedAssetRepository(null);
        $repository->existing = $asset;
        $stagingInspector = $this->createMock(ObjectStorageInspectorInterface::class);
        $stagingInspector->expects(self::never())->method('inspect');
        $finalizer = new ScriptedAssetFinalizer([$metadataOnlyFinal]);

        $returned = $this->confirmExisting(
            $this->service($repository, $stagingInspector, finalizer: $finalizer),
            $asset,
            $stagingKey,
        );
        self::assertSame($asset, $returned);

        $metadataOnlyStaging = new StoredObjectInspection(
            objectKey: $stagingKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: null,
            checksum: $asset->checksum,
            leadingBytes: substr($body, 0, 512),
            body: null,
        );
        $recoverySource = $this->inspectorReturning($metadataOnlyStaging);
        $mismatch = $this->captureValidation(fn () => $this->confirmExisting(
            $this->service($repository, $recoverySource, finalizer: new ScriptedAssetFinalizer()),
            $asset,
            $stagingKey,
        ));
        self::assertSame('asset_storage_final_mismatch', $mismatch->errorCode);
    }

    public function testPromotionAndCleanupDoubleFailureReportsCleanupRisk(): void
    {
        $body = AssetTestFixtures::image();
        $intent = $this->intent(AssetType::Image, strlen($body));
        $inspection = $this->inspection(
            $intent,
            checksum: 'sha256:' . hash('sha256', $body),
            body: $body,
            leadingBytes: substr($body, 0, 512),
        );
        $finalizer = new ScriptedAssetFinalizer();
        $finalizer->copyFailure = new \RuntimeException('Synthetic promotion failure.');
        $finalizer->deleteFailure = new \RuntimeException('Synthetic cleanup failure.');

        $exception = $this->captureValidation(fn () => $this->confirm(
            $this->service(
                $this->repositoryReturning($intent),
                $this->inspectorReturning($inspection),
                finalizer: $finalizer,
            ),
            $intent,
        ));

        self::assertSame('asset_storage_cleanup_failed', $exception->errorCode);
        self::assertSame(503, $exception->status);
        self::assertSame('Synthetic promotion failure.', $exception->getPrevious()?->getPrevious()?->getMessage());
    }

    private function service(
        AssetRepositoryInterface $repository,
        ?ObjectStorageInspectorInterface $inspector = null,
        ?callable $tokenGenerator = null,
        ?FabricCreativePayloadValidator $fabricValidator = null,
        ?callable $finalTokenGenerator = null,
        ?ObjectStorageAssetFinalizerInterface $finalizer = null,
    ): AssetUploadService {
        $inspector ??= $this->createStub(ObjectStorageInspectorInterface::class);
        $finalizer ??= $inspector instanceof ObjectStorageAssetFinalizerInterface
            ? $inspector
            : new class($inspector) implements ObjectStorageAssetFinalizerInterface {
                /** @var array<string, StoredObjectInspection> */
                private array $objects = [];

                public function __construct(private readonly ObjectStorageInspectorInterface $inspector)
                {
                }

                public function inspect(string $objectKey): ?StoredObjectInspection
                {
                    $stored = $this->objects[$objectKey] ?? $this->inspector->inspect($objectKey);
                    if ($stored === null) {
                        return null;
                    }

                    return new StoredObjectInspection(
                        objectKey: $objectKey,
                        contentType: $stored->contentType,
                        byteSize: $stored->byteSize,
                        width: $stored->width,
                        height: $stored->height,
                        durationSeconds: $stored->durationSeconds,
                        checksum: $stored->checksum,
                        leadingBytes: $stored->leadingBytes,
                        body: $stored->body,
                    );
                }

                public function writeFinalFromValidatedBytes(
                    StoredObjectInspection $stagingObject,
                    string $finalObjectKey,
                ): StoredObjectInspection {
                    return $this->objects[$finalObjectKey] = new StoredObjectInspection(
                        objectKey: $finalObjectKey,
                        contentType: $stagingObject->contentType,
                        byteSize: $stagingObject->byteSize,
                        width: $stagingObject->width,
                        height: $stagingObject->height,
                        durationSeconds: $stagingObject->durationSeconds,
                        checksum: $stagingObject->checksum,
                        leadingBytes: $stagingObject->leadingBytes,
                        body: $stagingObject->body,
                    );
                }

                public function delete(string $objectKey): void
                {
                    unset($this->objects[$objectKey]);
                }
            };

        return new AssetUploadService(
            $repository,
            $this->createStub(ObjectStorageUploadSignerInterface::class),
            $inspector,
            tokenGenerator: $tokenGenerator,
            fabricValidator: $fabricValidator,
            finalTokenGenerator: $finalTokenGenerator ?? static fn (): string => 'coverage-final-token',
            finalizer: $finalizer,
        );
    }

    private function repositoryReturning(AssetUploadIntent $intent): AssetRepositoryInterface
    {
        $repository = $this->createStub(AssetRepositoryInterface::class);
        $repository->method('findUploadIntentForConfirmation')->willReturn($intent);

        return $repository;
    }

    private function inspectorReturning(StoredObjectInspection $inspection): ObjectStorageInspectorInterface
    {
        $inspector = $this->createStub(ObjectStorageInspectorInterface::class);
        $inspector->method('inspect')->willReturn($inspection);

        return $inspector;
    }

    private function intent(AssetType $type, int $byteSize): AssetUploadIntent
    {
        [$filename, $extension, $contentType] = match ($type) {
            AssetType::Image => ['creative.png', 'png', 'image/png'],
            AssetType::Video => ['creative.mp4', 'mp4', 'video/mp4'],
            AssetType::FabricSnapshot => ['creative.json', 'json', 'application/json'],
            AssetType::Text => ['creative.txt', 'txt', 'text/plain'],
        };

        return new AssetUploadIntent(
            id: 41,
            organizationId: 99,
            uploaderUserId: 7,
            type: $type,
            originalFilename: $filename,
            objectKey: 'organizations/99/assets/staging/coverage-token.' . $extension,
            contentType: $contentType,
            byteSize: $byteSize,
            status: AssetStatus::PendingUpload,
            expiresAt: new \DateTimeImmutable('+1 hour'),
        );
    }

    private function inspection(
        AssetUploadIntent $intent,
        ?string $checksum,
        ?string $body,
        string $leadingBytes,
        int $width = 4,
        int $height = 3,
    ): StoredObjectInspection {
        return new StoredObjectInspection(
            objectKey: $intent->objectKey,
            contentType: $intent->contentType,
            byteSize: $intent->byteSize,
            width: $width,
            height: $height,
            durationSeconds: null,
            checksum: $checksum,
            leadingBytes: $leadingBytes,
            body: $body,
        );
    }

    private function assetFor(
        AssetUploadIntent $intent,
        string $body,
        int $id,
        string $finalToken = 'winner-final-token',
    ): CreativeAsset
    {
        return new CreativeAsset(
            id: $id,
            uploadIntentId: (int) $intent->id,
            organizationId: $intent->organizationId,
            uploaderUserId: $intent->uploaderUserId,
            type: $intent->type,
            objectKey: sprintf(
                'organizations/%d/assets/final/coverage-token/%s-sha256-%s.%s',
                $intent->organizationId,
                $finalToken,
                hash('sha256', $body),
                strtolower(pathinfo($intent->objectKey, PATHINFO_EXTENSION)),
            ),
            contentType: $intent->contentType,
            byteSize: $intent->byteSize,
            width: 4,
            height: 3,
            durationSeconds: null,
            checksum: 'sha256:' . hash('sha256', $body),
            status: AssetStatus::PendingReview,
        );
    }

    private function assetWithChecksum(?string $checksum): CreativeAsset
    {
        $asset = $this->confirmedAsset(AssetTestFixtures::image());

        return new CreativeAsset(
            id: $asset->id,
            uploadIntentId: $asset->uploadIntentId,
            organizationId: $asset->organizationId,
            uploaderUserId: $asset->uploaderUserId,
            type: $asset->type,
            objectKey: $asset->objectKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: $asset->durationSeconds,
            checksum: $checksum,
            status: $asset->status,
            snapshotStatus: $asset->snapshotStatus,
            snapshotPngObjectKey: $asset->snapshotPngObjectKey,
            snapshotWebpObjectKey: $asset->snapshotWebpObjectKey,
            thumbnailWebpObjectKey: $asset->thumbnailWebpObjectKey,
        );
    }

    private function confirmedAsset(string $body): CreativeAsset
    {
        return new CreativeAsset(
            id: 11,
            uploadIntentId: 7,
            organizationId: 99,
            uploaderUserId: 5,
            type: AssetType::Image,
            objectKey: 'organizations/99/assets/final/retry-token/fixture-final-token-sha256-'
                . hash('sha256', $body) . '.png',
            contentType: 'image/png',
            byteSize: strlen($body),
            width: 4,
            height: 3,
            durationSeconds: null,
            checksum: 'sha256:' . hash('sha256', $body),
            status: AssetStatus::PendingReview,
        );
    }

    private function stagingKeyForAsset(CreativeAsset $asset): string
    {
        if (preg_match(
            '~^(organizations/[1-9][0-9]*/assets)/final/([A-Za-z0-9_-]{8,128})/'
                . '[A-Za-z0-9_-]{16,128}-sha256-[a-f0-9]{64}\.([a-z0-9]+)$~D',
            $asset->objectKey,
            $matches,
        ) !== 1) {
            self::fail('Expected a canonical finalized asset key.');
        }

        return $matches[1] . '/staging/' . $matches[2] . '.' . $matches[3];
    }

    private function inspectionForAsset(CreativeAsset $asset, string $body): StoredObjectInspection
    {
        return new StoredObjectInspection(
            objectKey: $asset->objectKey,
            contentType: $asset->contentType,
            byteSize: $asset->byteSize,
            width: $asset->width,
            height: $asset->height,
            durationSeconds: $asset->durationSeconds,
            checksum: $asset->checksum,
            leadingBytes: substr($body, 0, 512),
            body: $body,
        );
    }

    private function confirmExisting(
        AssetUploadService $service,
        CreativeAsset $asset,
        string $stagingObjectKey,
    ): CreativeAsset {
        return $service->confirmUploadedAsset(
            $asset->organizationId,
            $asset->uploaderUserId,
            $asset->uploadIntentId,
            $stagingObjectKey,
            $asset->contentType,
            $asset->byteSize,
            $asset->checksum,
        );
    }

    private function confirm(
        AssetUploadService $service,
        AssetUploadIntent $intent,
        ?string $checksum = null,
    ): CreativeAsset
    {
        return $service->confirmUploadedAsset(
            $intent->organizationId,
            $intent->uploaderUserId,
            (int) $intent->id,
            $intent->objectKey,
            $intent->contentType,
            $intent->byteSize,
            $checksum,
        );
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
}

final class SyntheticAssetUniqueConstraintViolationException extends UniqueConstraintViolationException
{
    public function __construct()
    {
        parent::__construct(new SyntheticAssetDriverException(), null);
    }
}

final class SyntheticAssetDriverException extends \Exception implements DriverException
{
    public function __construct()
    {
        parent::__construct('Synthetic asset unique constraint violation.');
    }

    public function getSQLState(): ?string
    {
        return '23000';
    }
}

final class ScriptedAssetFinalizer implements ObjectStorageAssetFinalizerInterface
{
    /** @var array<string, StoredObjectInspection> */
    private array $objects = [];

    public ?\Throwable $inspectFailure = null;
    public ?\Throwable $copyFailure = null;
    public ?\Throwable $deleteFailure = null;

    /** @var list<string> */
    public array $deletedObjectKeys = [];

    /** @param list<StoredObjectInspection> $objects */
    public function __construct(array $objects = [])
    {
        foreach ($objects as $object) {
            $this->objects[$object->objectKey] = $object;
        }
    }

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        if ($this->inspectFailure !== null) {
            throw $this->inspectFailure;
        }

        return $this->objects[$objectKey] ?? null;
    }

    public function writeFinalFromValidatedBytes(
        StoredObjectInspection $stagingObject,
        string $finalObjectKey,
    ): StoredObjectInspection {
        if ($this->copyFailure !== null) {
            throw $this->copyFailure;
        }

        return $this->objects[$finalObjectKey] = new StoredObjectInspection(
            objectKey: $finalObjectKey,
            contentType: $stagingObject->contentType,
            byteSize: $stagingObject->byteSize,
            width: $stagingObject->width,
            height: $stagingObject->height,
            durationSeconds: $stagingObject->durationSeconds,
            checksum: $stagingObject->checksum,
            leadingBytes: $stagingObject->leadingBytes,
            body: $stagingObject->body,
        );
    }

    public function delete(string $objectKey): void
    {
        if ($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }

        unset($this->objects[$objectKey]);
        $this->deletedObjectKeys[] = $objectKey;
    }
}

final class ScriptedAssetRepository implements AssetRepositoryInterface
{
    public ?CreativeAsset $existing = null;
    public ?\Throwable $createFailure = null;
    public ?\Throwable $lookupFailure = null;

    public function __construct(private readonly ?AssetUploadIntent $intent)
    {
    }

    public function createUploadIntent(AssetUploadIntent $intent): AssetUploadIntent
    {
        return $intent;
    }

    public function findUploadIntentForConfirmation(
        int $id,
        int $organizationId,
        int $uploaderUserId,
    ): ?AssetUploadIntent {
        return $this->intent;
    }

    public function findAssetByUploadIntent(
        int $uploadIntentId,
        int $organizationId,
        int $uploaderUserId,
    ): ?CreativeAsset {
        if ($this->lookupFailure !== null) {
            throw $this->lookupFailure;
        }

        return $this->existing;
    }

    public function createAssetWithSnapshotJob(CreativeAsset $asset): CreativeAsset
    {
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        return $asset;
    }
}
