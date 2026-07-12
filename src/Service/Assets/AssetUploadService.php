<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetObjectKey;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Infrastructure\Storage\ObjectStorageAssetFinalizerInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Assets\AssetRepositoryInterface;

final class AssetUploadService
{
    /** @var callable(): string */
    private $tokenGenerator;

    /** @var callable(): string */
    private $finalTokenGenerator;

    private readonly ?ObjectStorageAssetFinalizerInterface $finalizer;

    /**
     * @param (callable(): string)|null $tokenGenerator
     */
    public function __construct(
        private readonly AssetRepositoryInterface $repository,
        private readonly ObjectStorageUploadSignerInterface $signer,
        private readonly ObjectStorageInspectorInterface $inspector,
        private readonly ?AssetUploadPolicy $policy = null,
        ?callable $tokenGenerator = null,
        private readonly ?FabricCreativePayloadValidator $fabricValidator = null,
        ?callable $finalTokenGenerator = null,
        ?ObjectStorageAssetFinalizerInterface $finalizer = null,
    ) {
        $this->tokenGenerator = $tokenGenerator ?? static fn (): string => bin2hex(random_bytes(16));
        $this->finalTokenGenerator = $finalTokenGenerator ?? static fn (): string => bin2hex(random_bytes(32));
        $this->finalizer = $finalizer
            ?? ($signer instanceof ObjectStorageAssetFinalizerInterface ? $signer : null)
            ?? ($inspector instanceof ObjectStorageAssetFinalizerInterface ? $inspector : null);
    }

    public function createUploadIntent(
        int $organizationId,
        int $uploaderUserId,
        string $type,
        string $filename,
        string $contentType,
        int $byteSize,
    ): AssetUploadIntent {
        if ($organizationId <= 0 || $uploaderUserId <= 0) {
            throw new AssetValidationException('asset_owner_invalid', 'Asset organization and uploader identifiers must be positive.');
        }

        $assetType = $this->validatedType($type);
        $filename = trim($filename);
        if (
            $filename === ''
            || strlen($filename) > 255
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
        ) {
            throw new AssetValidationException('asset_filename_invalid', 'Asset filename must be a safe basename of at most 255 bytes.');
        }
        $contentType = strtolower(trim($contentType));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->validateUploadShape($assetType, $extension, $contentType, $byteSize);

        $expiresAt = (new DateTimeImmutable())->modify('+' . $this->uploadPolicy()->uploadIntentTtlSeconds . ' seconds');
        $token = ($this->tokenGenerator)();
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $token) !== 1) {
            throw new AssetValidationException('asset_object_key_generation_failed', 'Asset object key token generation failed.', 500);
        }
        $objectKey = sprintf('organizations/%d/assets/staging/%s.%s', $organizationId, $token, $extension);
        new AssetObjectKey($objectKey);
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
        $upload = $this->signer->presignPut(new PresignedUploadRequest(
            objectKey: $stored->objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            expiresAt: $expiresAt,
            stagingOnly: true,
        ));

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
        if ($organizationId <= 0 || $uploaderUserId <= 0 || $uploadIntentId <= 0) {
            throw new AssetValidationException('asset_owner_invalid', 'Asset owner and upload intent identifiers must be positive.');
        }

        $clientChecksum = $this->normalizeProvidedChecksum($checksum);
        $intent = $this->repository->findUploadIntentForConfirmation($uploadIntentId, $organizationId, $uploaderUserId);
        if ($intent === null) {
            $existing = $this->repository->findAssetByUploadIntent($uploadIntentId, $organizationId, $uploaderUserId);
            if ($existing !== null) {
                $this->validateConfirmedRetry($existing, $objectKey, $contentType, $byteSize, $clientChecksum);
                $this->validatePersistedFinalObject($existing, $objectKey);
                $this->deleteStagingObject($objectKey);

                return $existing;
            }

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

        $authoritativeChecksum = $this->normalizeChecksum($stored->checksum);
        if ($authoritativeChecksum === null) {
            throw new AssetValidationException(
                'asset_uploaded_checksum_unavailable',
                'Object storage did not return an authoritative asset checksum.',
                503,
            );
        }

        if ($clientChecksum !== null && !hash_equals($authoritativeChecksum, $clientChecksum)) {
            throw new AssetValidationException('asset_uploaded_object_mismatch', 'Stored object checksum does not match the confirmation payload.');
        }
        if ($stored->body !== null) {
            if (
                strlen($stored->body) !== $stored->byteSize
                || !hash_equals($authoritativeChecksum, 'sha256:' . hash('sha256', $stored->body))
            ) {
                throw new AssetValidationException(
                    'asset_uploaded_object_mismatch',
                    'Stored object bytes do not match authoritative metadata.',
                );
            }
        }

        $width = $stored->width;
        $height = $stored->height;
        if ($intent->type === AssetType::FabricSnapshot) {
            if ($stored->body === null) {
                throw new AssetValidationException(
                    'asset_fabric_payload_unavailable',
                    'Fabric creative bytes are required for controlled-renderer validation.',
                );
            }

            $fabric = ($this->fabricValidator ?? new FabricCreativePayloadValidator())->validate($stored->body);
            $width = $fabric->width;
            $height = $fabric->height;
        }

        $this->validateDimensions($intent->type, $width, $height, $stored->durationSeconds);
        $this->validateMagic($contentType, $stored->body === null ? $stored->leadingBytes : substr($stored->body, 0, 512));
        if ($stored->body === null) {
            throw new AssetValidationException(
                'asset_uploaded_body_unavailable',
                'Authoritative uploaded asset bytes are required for immutable finalization.',
                503,
            );
        }

        $finalizer = $this->requiredFinalizer();
        $finalObjectKey = $this->finalObjectKey($intent, $authoritativeChecksum);
        try {
            $promoted = $finalizer->writeFinalFromValidatedBytes($stored, $finalObjectKey);
            $this->validatePromotedObject($promoted, $stored, $finalObjectKey, $authoritativeChecksum);
        } catch (\Throwable $exception) {
            $this->deleteAfterFailure($finalizer, $finalObjectKey, $exception);

            if ($exception instanceof AssetValidationException) {
                throw $exception;
            }

            throw new AssetValidationException(
                'asset_storage_promotion_failed',
                'Uploaded asset could not be finalized in object storage.',
                503,
                $exception,
            );
        }

        $asset = new CreativeAsset(
            id: null,
            uploadIntentId: (int) $intent->id,
            organizationId: $organizationId,
            uploaderUserId: $uploaderUserId,
            type: $intent->type,
            objectKey: $finalObjectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            width: $width,
            height: $height,
            durationSeconds: $stored->durationSeconds,
            checksum: $authoritativeChecksum,
            status: AssetStatus::PendingReview,
        );

        try {
            $created = $this->repository->createAssetWithSnapshotJob($asset);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->repository->findAssetByUploadIntent(
                (int) $intent->id,
                $organizationId,
                $uploaderUserId,
            );
            if ($existing === null) {
                $this->deleteAfterFailure($finalizer, $finalObjectKey, $exception);

                throw $exception;
            }

            if ($existing->objectKey !== $finalObjectKey) {
                $this->deleteAfterFailure($finalizer, $finalObjectKey, $exception);
            }
            $this->validateConfirmedRetry($existing, $objectKey, $contentType, $byteSize, $authoritativeChecksum);
            $this->validatePersistedFinalObject($existing, $objectKey);
            $this->deleteStagingObject($objectKey);

            return $existing;
        } catch (\Throwable $exception) {
            try {
                $existing = $this->repository->findAssetByUploadIntent(
                    (int) $intent->id,
                    $organizationId,
                    $uploaderUserId,
                );
            } catch (\Throwable) {
                // The commit outcome is unknown, so retaining the candidate final
                // object is safer than risking a database-to-object drift.
                throw $exception;
            }
            if ($existing !== null) {
                if ($existing->objectKey !== $finalObjectKey) {
                    $this->deleteAfterFailure($finalizer, $finalObjectKey, $exception);
                }
                $this->validateConfirmedRetry(
                    $existing,
                    $objectKey,
                    $contentType,
                    $byteSize,
                    $authoritativeChecksum,
                );
                $this->validatePersistedFinalObject($existing, $objectKey);
                $this->deleteStagingObject($objectKey);

                return $existing;
            }

            $this->deleteAfterFailure($finalizer, $finalObjectKey, $exception);

            throw $exception;
        }

        $this->deleteStagingObject($objectKey);

        return $created;
    }

    private function validateConfirmedRetry(
        CreativeAsset $asset,
        string $objectKey,
        string $contentType,
        int $byteSize,
        ?string $checksum,
    ): void {
        $contentType = strtolower(trim($contentType));
        if (
            !$this->assetKeyMatchesStagingKey($asset->objectKey, $objectKey)
            || $asset->contentType !== $contentType
            || $asset->byteSize !== $byteSize
        ) {
            throw new AssetValidationException(
                'asset_upload_metadata_mismatch',
                'Uploaded asset metadata does not match the already confirmed asset.',
            );
        }
        $assetChecksum = $this->normalizeChecksum($asset->checksum);
        if ($assetChecksum === null || $asset->checksum !== $assetChecksum) {
            throw new AssetValidationException(
                'asset_uploaded_checksum_unavailable',
                'Confirmed asset does not contain an authoritative checksum.',
                503,
            );
        }
        if (!$this->assetKeyMatchesStagingKey($asset->objectKey, $objectKey, $assetChecksum)) {
            throw new AssetValidationException(
                'asset_upload_metadata_mismatch',
                'Uploaded asset metadata does not match the already confirmed asset.',
            );
        }

        if ($checksum !== null && !hash_equals($assetChecksum, $checksum)) {
            throw new AssetValidationException(
                'asset_upload_metadata_mismatch',
                'Uploaded asset metadata does not match the already confirmed asset.',
            );
        }
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

        if (
            $type === AssetType::FabricSnapshot
            && ($width > $policy->maxWidth(AssetType::Image) || $height > $policy->maxHeight(AssetType::Image))
        ) {
            throw new AssetValidationException('asset_dimensions_out_of_bounds', 'Fabric snapshot dimensions exceed allowed bounds.');
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

    private function normalizeProvidedChecksum(?string $checksum): ?string
    {
        if ($checksum === null || trim($checksum) === '') {
            return null;
        }

        $normalized = $this->normalizeChecksum($checksum);
        if ($normalized === null) {
            throw new AssetValidationException(
                'asset_upload_metadata_mismatch',
                'Uploaded asset checksum format is invalid.',
            );
        }

        return $normalized;
    }

    private function normalizeChecksum(?string $checksum): ?string
    {
        if ($checksum === null || trim($checksum) === '') {
            return null;
        }
        if (preg_match('/^(?:sha256:)?([a-fA-F0-9]{64})$/D', trim($checksum), $matches) !== 1) {
            return null;
        }

        return 'sha256:' . strtolower($matches[1]);
    }

    private function finalObjectKey(AssetUploadIntent $intent, string $checksum): string
    {
        if (preg_match(
            '~^organizations/([1-9][0-9]*)/assets/staging/([A-Za-z0-9_-]{8,128})\.([a-z0-9]+)$~D',
            $intent->objectKey,
            $matches,
        ) !== 1 || (int) $matches[1] !== $intent->organizationId) {
            throw new AssetValidationException(
                'asset_object_key_generation_failed',
                'Asset staging object key is invalid.',
                500,
            );
        }
        $token = ($this->finalTokenGenerator)();
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $token) !== 1) {
            throw new AssetValidationException(
                'asset_object_key_generation_failed',
                'Asset final object key token generation failed.',
                500,
            );
        }

        $objectKey = sprintf(
            'organizations/%d/assets/final/%s/%s-sha256-%s.%s',
            $intent->organizationId,
            $matches[2],
            $token,
            substr($checksum, strlen('sha256:')),
            $matches[3],
        );
        new AssetObjectKey($objectKey);

        return $objectKey;
    }

    private function assetKeyMatchesStagingKey(
        string $assetObjectKey,
        string $stagingObjectKey,
        ?string $expectedChecksum = null,
    ): bool
    {
        if (preg_match(
            '~^(organizations/[1-9][0-9]*/assets)/staging/([A-Za-z0-9_-]{8,128})\.([a-z0-9]+)$~D',
            $stagingObjectKey,
            $matches,
        ) !== 1) {
            return false;
        }

        return preg_match(
            '~^' . preg_quote($matches[1], '~') . '/final/' . preg_quote($matches[2], '~')
            . '/[A-Za-z0-9_-]{16,128}-sha256-([a-f0-9]{64})\.' . preg_quote($matches[3], '~') . '$~D',
            $assetObjectKey,
            $finalMatches,
        ) === 1 && ($expectedChecksum === null
            || hash_equals(substr($expectedChecksum, strlen('sha256:')), strtolower($finalMatches[1])));
    }

    private function validatePromotedObject(
        StoredObjectInspection $promoted,
        StoredObjectInspection $staging,
        string $finalObjectKey,
        string $authoritativeChecksum,
    ): void {
        $promotedChecksum = $this->normalizeChecksum($promoted->checksum);
        if (
            $promoted->objectKey !== $finalObjectKey
            || $promoted->contentType !== $staging->contentType
            || $promoted->byteSize !== $staging->byteSize
            || $promotedChecksum === null
            || !hash_equals($authoritativeChecksum, $promotedChecksum)
            || $promoted->body === null
            || strlen($promoted->body) !== $promoted->byteSize
            || !hash_equals($promotedChecksum, 'sha256:' . hash('sha256', $promoted->body))
        ) {
            throw new AssetValidationException(
                'asset_storage_promotion_mismatch',
                'Finalized asset bytes do not match the validated staging object.',
                503,
            );
        }
    }

    private function validatePersistedFinalObject(CreativeAsset $asset, string $stagingObjectKey): void
    {
        $finalizer = $this->requiredFinalizer();
        try {
            $stored = $finalizer->inspect($asset->objectKey);
        } catch (\Throwable $exception) {
            throw new AssetValidationException(
                'asset_storage_final_verification_failed',
                'Confirmed asset could not be verified in object storage.',
                503,
                $exception,
            );
        }

        $assetChecksum = $this->normalizeChecksum($asset->checksum);
        if ($assetChecksum !== null && $this->storedObjectMatchesAsset(
            $stored,
            $asset,
            $assetChecksum,
            $asset->objectKey,
            requireBody: false,
        )) {
            return;
        }

        try {
            $staging = $this->inspector->inspect($stagingObjectKey);
        } catch (\Throwable $exception) {
            throw new AssetValidationException(
                'asset_storage_final_verification_failed',
                'Confirmed asset recovery source could not be inspected.',
                503,
                $exception,
            );
        }
        if (!$this->storedObjectMatchesAsset(
            $staging,
            $asset,
            $assetChecksum,
            $stagingObjectKey,
            requireBody: true,
        )) {
            throw new AssetValidationException(
                'asset_storage_final_mismatch',
                'Confirmed asset bytes no longer match the database record.',
                503,
            );
        }

        try {
            $recovered = $finalizer->writeFinalFromValidatedBytes($staging, $asset->objectKey);
            $this->validatePromotedObject($recovered, $staging, $asset->objectKey, $assetChecksum);
        } catch (\Throwable $exception) {
            if ($exception instanceof AssetValidationException) {
                throw $exception;
            }

            throw new AssetValidationException(
                'asset_storage_final_verification_failed',
                'Confirmed asset could not be recovered in object storage.',
                503,
                $exception,
            );
        }
    }

    private function storedObjectMatchesAsset(
        ?StoredObjectInspection $stored,
        CreativeAsset $asset,
        ?string $assetChecksum,
        string $expectedObjectKey,
        bool $requireBody,
    ): bool {
        $storedChecksum = $stored === null ? null : $this->normalizeChecksum($stored->checksum);

        $metadataMatches = $stored !== null
            && $assetChecksum !== null
            && $storedChecksum !== null
            && $stored->objectKey === $expectedObjectKey
            && $stored->contentType === $asset->contentType
            && $stored->byteSize === $asset->byteSize
            && hash_equals($assetChecksum, $storedChecksum);
        if (!$metadataMatches) {
            return false;
        }
        if ($stored->body === null) {
            return !$requireBody;
        }

        return strlen($stored->body) === $stored->byteSize
            && hash_equals($storedChecksum, 'sha256:' . hash('sha256', $stored->body));
    }

    private function requiredFinalizer(): ObjectStorageAssetFinalizerInterface
    {
        return $this->finalizer ?? throw new AssetValidationException(
            'asset_storage_finalization_unavailable',
            'Immutable asset finalization is not configured.',
            503,
        );
    }

    private function deleteStagingObject(string $objectKey): void
    {
        try {
            $this->requiredFinalizer()->delete($objectKey);
        } catch (\Throwable $exception) {
            throw new AssetValidationException(
                'asset_storage_cleanup_failed',
                'The finalized asset is safe, but its staging object could not be removed.',
                503,
                $exception,
            );
        }
    }

    private function deleteAfterFailure(
        ObjectStorageAssetFinalizerInterface $finalizer,
        string $objectKey,
        \Throwable $previous,
    ): void {
        try {
            $finalizer->delete($objectKey);
        } catch (\Throwable $cleanupException) {
            throw new AssetValidationException(
                'asset_storage_cleanup_failed',
                'An uncommitted finalized asset could not be removed from object storage.',
                503,
                new \RuntimeException($cleanupException->getMessage(), 0, $previous),
            );
        }
    }
}
