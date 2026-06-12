<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use DateTimeImmutable;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Repository\Assets\AssetRepositoryInterface;

final class AssetUploadService
{
    /** @var callable(): string */
    private $tokenGenerator;

    /**
     * @param (callable(): string)|null $tokenGenerator
     */
    public function __construct(
        private readonly AssetRepositoryInterface $repository,
        private readonly ObjectStorageUploadSignerInterface $signer,
        private readonly ObjectStorageInspectorInterface $inspector,
        private readonly ?AssetUploadPolicy $policy = null,
        ?callable $tokenGenerator = null,
    ) {
        $this->tokenGenerator = $tokenGenerator ?? static fn (): string => bin2hex(random_bytes(16));
    }

    public function createUploadIntent(
        int $organizationId,
        int $uploaderUserId,
        string $type,
        string $filename,
        string $contentType,
        int $byteSize,
    ): AssetUploadIntent {
        $assetType = $this->validatedType($type);
        $filename = trim($filename);
        $contentType = strtolower(trim($contentType));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->validateUploadShape($assetType, $extension, $contentType, $byteSize);

        $expiresAt = (new DateTimeImmutable())->modify('+' . $this->uploadPolicy()->uploadIntentTtlSeconds . ' seconds');
        $token = ($this->tokenGenerator)();
        $objectKey = sprintf('organizations/%d/assets/%s.%s', $organizationId, $token, $extension);
        $stored = $this->repository->createUploadIntent(new AssetUploadIntent(
            id: null,
            organizationId: $organizationId,
            uploaderUserId: $uploaderUserId,
            type: $assetType,
            originalFilename: $filename,
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            status: AssetStatus::PendingUpload,
            expiresAt: $expiresAt,
        ));
        $upload = $this->signer->presignPut(new PresignedUploadRequest($stored->objectKey, $contentType, $byteSize, $expiresAt));

        return new AssetUploadIntent(
            id: $stored->id,
            organizationId: $stored->organizationId,
            uploaderUserId: $stored->uploaderUserId,
            type: $stored->type,
            originalFilename: $stored->originalFilename,
            objectKey: $stored->objectKey,
            contentType: $stored->contentType,
            byteSize: $stored->byteSize,
            status: $stored->status,
            expiresAt: $stored->expiresAt,
            upload: $upload,
        );
    }

    public function confirmUploadedAsset(
        int $organizationId,
        int $uploaderUserId,
        int $uploadIntentId,
        string $objectKey,
        string $contentType,
        int $byteSize,
        ?string $checksum = null,
    ): CreativeAsset {
        $intent = $this->repository->findUploadIntentForConfirmation($uploadIntentId, $organizationId, $uploaderUserId);
        if ($intent === null) {
            throw new AssetValidationException('asset_upload_intent_not_found', 'Upload intent was not found.', 404);
        }

        if ($intent->expiresAt <= new DateTimeImmutable()) {
            throw new AssetValidationException('asset_upload_intent_expired', 'Upload intent has expired.');
        }

        $contentType = strtolower(trim($contentType));
        if ($objectKey !== $intent->objectKey || $contentType !== $intent->contentType || $byteSize !== $intent->byteSize) {
            throw new AssetValidationException('asset_upload_metadata_mismatch', 'Uploaded asset metadata does not match the upload intent.');
        }

        $stored = $this->inspector->inspect($intent->objectKey);
        if ($stored === null) {
            throw new AssetValidationException('asset_uploaded_object_not_found', 'Uploaded object was not found in object storage.', 404);
        }

        if (
            $stored->objectKey !== $intent->objectKey
            || $stored->contentType !== $intent->contentType
            || $stored->byteSize !== $intent->byteSize
        ) {
            throw new AssetValidationException('asset_uploaded_object_mismatch', 'Stored object metadata does not match the upload intent.');
        }

        if ($checksum !== null && trim($checksum) !== '' && $stored->checksum !== null && trim($checksum) !== $stored->checksum) {
            throw new AssetValidationException('asset_uploaded_object_mismatch', 'Stored object checksum does not match the confirmation payload.');
        }

        $this->validateDimensions($intent->type, $stored->width, $stored->height, $stored->durationSeconds);
        $this->validateMagic($contentType, $stored->leadingBytes);

        return $this->repository->createAssetWithSnapshotJob(new CreativeAsset(
            id: null,
            uploadIntentId: (int) $intent->id,
            organizationId: $organizationId,
            uploaderUserId: $uploaderUserId,
            type: $intent->type,
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            width: $stored->width,
            height: $stored->height,
            durationSeconds: $stored->durationSeconds,
            checksum: $stored->checksum ?? ($checksum === null ? null : trim($checksum)),
            status: AssetStatus::PendingReview,
        ));
    }

    private function validatedType(string $type): AssetType
    {
        $assetType = AssetType::tryFrom(trim($type));
        if ($assetType === null) {
            throw new AssetValidationException('asset_type_not_allowed', 'Asset type is not allowed.');
        }

        return $assetType;
    }

    private function validateUploadShape(AssetType $type, string $extension, string $contentType, int $byteSize): void
    {
        $policy = $this->uploadPolicy();
        $allowed = $policy->allowedContentTypes($type);
        if ($policy->isBlockedExtension($extension) || $policy->isBlockedContentType($contentType)) {
            throw new AssetValidationException('asset_type_not_allowed', 'Executable or markup uploads are not allowed.');
        }

        if (!isset($allowed[$extension]) || $allowed[$extension] !== $contentType) {
            throw new AssetValidationException('asset_mime_extension_mismatch', 'Content type and extension do not match.');
        }

        $limit = $policy->maxBytes($type);
        if ($byteSize <= 0 || $byteSize > $limit) {
            throw new AssetValidationException('asset_too_large', 'Asset byte size is outside allowed bounds.');
        }
    }

    private function validateDimensions(AssetType $type, int $width, int $height, ?float $durationSeconds): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new AssetValidationException('asset_dimensions_out_of_bounds', 'Asset dimensions must be positive.');
        }

        $policy = $this->uploadPolicy();
        if ($type === AssetType::Image && ($width > $policy->maxWidth($type) || $height > $policy->maxHeight($type))) {
            throw new AssetValidationException('asset_dimensions_out_of_bounds', 'Image dimensions exceed allowed bounds.');
        }

        if ($type === AssetType::Video) {
            if ($width > $policy->maxWidth($type) || $height > $policy->maxHeight($type)) {
                throw new AssetValidationException('asset_video_resolution_out_of_bounds', 'Video resolution exceeds allowed bounds.');
            }

            if ($durationSeconds === null || $durationSeconds <= 0 || $durationSeconds > $policy->maxDurationSeconds($type)) {
                throw new AssetValidationException('asset_duration_out_of_bounds', 'Video duration is outside allowed bounds.');
            }
        }
    }

    private function validateMagic(string $contentType, string $bytes): void
    {
        if ($bytes === '') {
            throw new AssetValidationException('asset_magic_mismatch', 'Magic bytes are required.');
        }

        if (!$this->uploadPolicy()->matchesMagic($contentType, $bytes)) {
            throw new AssetValidationException('asset_magic_mismatch', 'Magic bytes do not match content type.');
        }
    }

    private function uploadPolicy(): AssetUploadPolicy
    {
        return $this->policy ?? AssetUploadPolicy::default();
    }
}
