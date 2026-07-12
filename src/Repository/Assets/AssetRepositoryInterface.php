<?php

declare(strict_types=1);

namespace VertoAD\Repository\Assets;

use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Domain\Assets\CreativeAsset;

interface AssetRepositoryInterface
{
    public function createUploadIntent(AssetUploadIntent $intent): AssetUploadIntent;

    public function findUploadIntentForConfirmation(int $id, int $organizationId, int $uploaderUserId): ?AssetUploadIntent;

    public function findAssetByUploadIntent(int $uploadIntentId, int $organizationId, int $uploaderUserId): ?CreativeAsset;

    public function createAssetWithSnapshotJob(CreativeAsset $asset): CreativeAsset;
}
