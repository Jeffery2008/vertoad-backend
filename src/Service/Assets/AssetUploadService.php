<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use DateTimeImmutable;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Repository\Assets\AssetRepositoryInterface;

final class AssetUploadService
{
    /** @var callable(): string */
    private $tokenGenerator;

    /**
     * @param array<string, mixed> $limits
     * @param (callable(): string)|null $tokenGenerator
     */
    public function __construct(
        private readonly AssetRepositoryInterface $repository,
        private readonly ObjectStorageUploadSignerInterface $signer,
        private readonly array $limits = [],
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

        $expiresAt = (new DateTimeImmutable())->modify('+' . $this->intLimit('upload_intent_ttl_seconds', 900) . ' seconds');
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
        int $width,
        int $height,
        ?float $durationSeconds,
        ?string $checksum,
        string $magicBase64,
    ): CreativeAsset {
        $intent = $this->repository->findUploadIntentForConfirmation($uploadIntentId, $organizationId, $uploaderUserId);
        if ($intent === null) {
            throw new AssetValidationException('asset_upload_intent_not_found', 'Upload intent was not found.', 404);
        }

        $contentType = strtolower(trim($contentType));
        if ($objectKey !== $intent->objectKey || $contentType !== $intent->contentType || $byteSize !== $intent->byteSize) {
            throw new AssetValidationException('asset_upload_metadata_mismatch', 'Uploaded asset metadata does not match the upload intent.');
        }

        $this->validateDimensions($intent->type, $width, $height, $durationSeconds);
        $this->validateMagic($contentType, $magicBase64);

        return $this->repository->createAssetWithSnapshotJob(new CreativeAsset(
            id: null,
            uploadIntentId: (int) $intent->id,
            organizationId: $organizationId,
            uploaderUserId: $uploaderUserId,
            type: $intent->type,
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            width: $width,
            height: $height,
            durationSeconds: $durationSeconds,
            checksum: $checksum === null ? null : trim($checksum),
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
        $allowed = $this->allowedContentTypes($type);
        if (in_array($extension, ['html', 'htm', 'js', 'mjs', 'svg'], true) || in_array($contentType, ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'], true)) {
            throw new AssetValidationException('asset_type_not_allowed', 'Executable or markup uploads are not allowed.');
        }

        if (!isset($allowed[$extension]) || $allowed[$extension] !== $contentType) {
            throw new AssetValidationException('asset_mime_extension_mismatch', 'Content type and extension do not match.');
        }

        $limit = match ($type) {
            AssetType::Image => $this->intLimit('image_max_bytes', 10_485_760),
            AssetType::Video => $this->intLimit('video_max_bytes', 209_715_200),
            AssetType::FabricSnapshot, AssetType::Text => $this->intLimit('snapshot_max_bytes', 1_048_576),
        };
        if ($byteSize <= 0 || $byteSize > $limit) {
            throw new AssetValidationException('asset_too_large', 'Asset byte size is outside allowed bounds.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function allowedContentTypes(AssetType $type): array
    {
        return match ($type) {
            AssetType::Image => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'],
            AssetType::Video => ['mp4' => 'video/mp4', 'webm' => 'video/webm'],
            AssetType::FabricSnapshot => ['json' => 'application/json'],
            AssetType::Text => ['txt' => 'text/plain'],
        };
    }

    private function validateDimensions(AssetType $type, int $width, int $height, ?float $durationSeconds): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new AssetValidationException('asset_dimensions_out_of_bounds', 'Asset dimensions must be positive.');
        }

        if ($type === AssetType::Image && ($width > $this->intLimit('image_max_width', 4096) || $height > $this->intLimit('image_max_height', 4096))) {
            throw new AssetValidationException('asset_dimensions_out_of_bounds', 'Image dimensions exceed allowed bounds.');
        }

        if ($type === AssetType::Video) {
            if ($width > $this->intLimit('video_max_width', 3840) || $height > $this->intLimit('video_max_height', 2160)) {
                throw new AssetValidationException('asset_video_resolution_out_of_bounds', 'Video resolution exceeds allowed bounds.');
            }

            if ($durationSeconds === null || $durationSeconds <= 0 || $durationSeconds > $this->floatLimit('video_max_duration_seconds', 120.0)) {
                throw new AssetValidationException('asset_duration_out_of_bounds', 'Video duration is outside allowed bounds.');
            }
        }
    }

    private function validateMagic(string $contentType, string $magicBase64): void
    {
        $bytes = base64_decode($magicBase64, true);
        if ($bytes === false || $bytes === '') {
            throw new AssetValidationException('asset_magic_mismatch', 'Magic bytes are required.');
        }

        $matches = match ($contentType) {
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'image/webp' => strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            'video/mp4' => strlen($bytes) >= 8 && substr($bytes, 4, 4) === 'ftyp',
            'video/webm' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
            'application/json' => $this->looksLikeJson($bytes),
            'text/plain' => !str_contains(strtolower(substr($bytes, 0, 256)), '<script'),
            default => false,
        };

        if (!$matches) {
            throw new AssetValidationException('asset_magic_mismatch', 'Magic bytes do not match content type.');
        }
    }

    private function looksLikeJson(string $bytes): bool
    {
        $trimmed = ltrim($bytes);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    private function intLimit(string $key, int $default): int
    {
        return (int) ($this->limits[$key] ?? $default);
    }

    private function floatLimit(string $key, float $default): float
    {
        return (float) ($this->limits[$key] ?? $default);
    }
}
