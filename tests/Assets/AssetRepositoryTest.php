<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Assets\AssetSnapshotStatus;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Repository\Assets\AssetRepository;

final class AssetRepositoryTest extends TestCase
{
    public function testFindAssetHydratesEveryPersistedField(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        AssetSchema::create($connection);
        $checksum = 'sha256:' . str_repeat('a', 64);
        $connection->insert('creative_assets', [
            'upload_intent_id' => 42,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => AssetType::Video->value,
            'object_key' => 'organizations/99/assets/creative.mp4',
            'content_type' => 'video/mp4',
            'byte_size' => 2048,
            'width' => 1920,
            'height' => 1080,
            'duration_seconds' => 12.5,
            'checksum' => $checksum,
            'status' => AssetStatus::PendingReview->value,
            'snapshot_status' => AssetSnapshotStatus::Ready->value,
            'snapshot_png_object_key' => 'organizations/99/assets/snapshots/creative.png',
            'snapshot_webp_object_key' => 'organizations/99/assets/snapshots/creative.webp',
            'thumbnail_webp_object_key' => 'organizations/99/assets/thumbnails/creative.webp',
        ]);

        $asset = (new AssetRepository($connection))->findAssetByUploadIntent(42, 99, 7);

        self::assertNotNull($asset);
        self::assertSame((int) $connection->lastInsertId(), $asset->id);
        self::assertSame(42, $asset->uploadIntentId);
        self::assertSame(99, $asset->organizationId);
        self::assertSame(7, $asset->uploaderUserId);
        self::assertSame(AssetType::Video, $asset->type);
        self::assertSame('organizations/99/assets/creative.mp4', $asset->objectKey);
        self::assertSame('video/mp4', $asset->contentType);
        self::assertSame(2048, $asset->byteSize);
        self::assertSame(1920, $asset->width);
        self::assertSame(1080, $asset->height);
        self::assertSame(12.5, $asset->durationSeconds);
        self::assertSame($checksum, $asset->checksum);
        self::assertSame(AssetStatus::PendingReview, $asset->status);
        self::assertSame(AssetSnapshotStatus::Ready, $asset->snapshotStatus);
        self::assertSame('organizations/99/assets/snapshots/creative.png', $asset->snapshotPngObjectKey);
        self::assertSame('organizations/99/assets/snapshots/creative.webp', $asset->snapshotWebpObjectKey);
        self::assertSame('organizations/99/assets/thumbnails/creative.webp', $asset->thumbnailWebpObjectKey);
    }
}
