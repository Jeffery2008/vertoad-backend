<?php

declare(strict_types=1);

namespace VertoAD\Repository\Assets;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetSnapshotStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;

final class AssetRepository implements AssetRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createUploadIntent(AssetUploadIntent $intent): AssetUploadIntent
    {
        $this->connection->insert('asset_upload_intents', [
            'organization_id' => $intent->organizationId,
            'uploader_user_id' => $intent->uploaderUserId,
            'type' => $intent->type->value,
            'original_filename' => $intent->originalFilename,
            'object_key' => $intent->objectKey,
            'content_type' => $intent->contentType,
            'byte_size' => $intent->byteSize,
            'status' => $intent->status->value,
            'expires_at' => $this->formatDate($intent->expiresAt),
        ]);

        return $this->findUploadIntentForConfirmation(
            (int) $this->connection->lastInsertId(),
            $intent->organizationId,
            $intent->uploaderUserId,
        ) ?? $intent;
    }

    public function findUploadIntentForConfirmation(int $id, int $organizationId, int $uploaderUserId): ?AssetUploadIntent
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'organization_id',
                'uploader_user_id',
                'type',
                'original_filename',
                'object_key',
                'content_type',
                'byte_size',
                'status',
                'expires_at',
            )
            ->from('asset_upload_intents')
            ->where('id = :id')
            ->andWhere('organization_id = :organization_id')
            ->andWhere('uploader_user_id = :uploader_user_id')
            ->andWhere('status = :status')
            ->setParameter('id', $id)
            ->setParameter('organization_id', $organizationId)
            ->setParameter('uploader_user_id', $uploaderUserId)
            ->setParameter('status', AssetStatus::PendingUpload->value)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateIntent($row);
    }

    public function createAssetWithSnapshotJob(CreativeAsset $asset): CreativeAsset
    {
        return $this->connection->transactional(function () use ($asset): CreativeAsset {
            $this->connection->insert('creative_assets', [
                'upload_intent_id' => $asset->uploadIntentId,
                'organization_id' => $asset->organizationId,
                'uploader_user_id' => $asset->uploaderUserId,
                'type' => $asset->type->value,
                'object_key' => $asset->objectKey,
                'content_type' => $asset->contentType,
                'byte_size' => $asset->byteSize,
                'width' => $asset->width,
                'height' => $asset->height,
                'duration_seconds' => $asset->durationSeconds,
                'checksum' => $asset->checksum,
                'status' => $asset->status->value,
                'snapshot_status' => $asset->snapshotStatus->value,
            ]);
            $assetId = (int) $this->connection->lastInsertId();
            $this->connection->insert('asset_snapshot_jobs', [
                'asset_id' => $assetId,
                'organization_id' => $asset->organizationId,
                'status' => 'pending',
                'attempts' => 0,
            ]);
            $this->connection->update(
                'asset_upload_intents',
                ['status' => AssetStatus::PendingReview->value],
                ['id' => $asset->uploadIntentId],
            );

            return new CreativeAsset(
                id: $assetId,
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
                snapshotStatus: $asset->snapshotStatus,
                snapshotPngObjectKey: $asset->snapshotPngObjectKey,
                snapshotWebpObjectKey: $asset->snapshotWebpObjectKey,
                thumbnailWebpObjectKey: $asset->thumbnailWebpObjectKey,
            );
        });
    }

    public function findAssetByUploadIntent(int $uploadIntentId, int $organizationId, int $uploaderUserId): ?CreativeAsset
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('creative_assets')
            ->where('upload_intent_id = :upload_intent_id')
            ->andWhere('organization_id = :organization_id')
            ->andWhere('uploader_user_id = :uploader_user_id')
            ->setParameter('upload_intent_id', $uploadIntentId)
            ->setParameter('organization_id', $organizationId)
            ->setParameter('uploader_user_id', $uploaderUserId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateAsset($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateIntent(array $row): AssetUploadIntent
    {
        return new AssetUploadIntent(
            id: (int) $row['id'],
            organizationId: (int) $row['organization_id'],
            uploaderUserId: (int) $row['uploader_user_id'],
            type: AssetType::from((string) $row['type']),
            originalFilename: (string) $row['original_filename'],
            objectKey: (string) $row['object_key'],
            contentType: (string) $row['content_type'],
            byteSize: (int) $row['byte_size'],
            status: AssetStatus::from((string) $row['status']),
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateAsset(array $row): CreativeAsset
    {
        return new CreativeAsset(
            id: (int) $row['id'],
            uploadIntentId: (int) $row['upload_intent_id'],
            organizationId: (int) $row['organization_id'],
            uploaderUserId: (int) $row['uploader_user_id'],
            type: AssetType::from((string) $row['type']),
            objectKey: (string) $row['object_key'],
            contentType: (string) $row['content_type'],
            byteSize: (int) $row['byte_size'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            durationSeconds: $row['duration_seconds'] === null ? null : (float) $row['duration_seconds'],
            checksum: $row['checksum'] === null ? null : (string) $row['checksum'],
            status: AssetStatus::from((string) $row['status']),
            snapshotStatus: AssetSnapshotStatus::from((string) $row['snapshot_status']),
            snapshotPngObjectKey: $row['snapshot_png_object_key'] === null ? null : (string) $row['snapshot_png_object_key'],
            snapshotWebpObjectKey: $row['snapshot_webp_object_key'] === null ? null : (string) $row['snapshot_webp_object_key'],
            thumbnailWebpObjectKey: $row['thumbnail_webp_object_key'] === null ? null : (string) $row['thumbnail_webp_object_key'],
        );
    }

    private function formatDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
