<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use GdImage;
use VertoAD\Domain\Assets\AssetObjectKey;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\CreativeAsset;

final readonly class AssetSnapshotGenerator
{
    public function __construct(
        private FabricCreativePayloadValidator $fabricValidator,
        private FfmpegAssetFrameExtractor $videoFrames,
        private int $maxWidth = 1920,
        private int $maxHeight = 1920,
        private int $thumbnailMaxWidth = 640,
        private int $thumbnailMaxHeight = 640,
        private int $webpQuality = 85,
    ) {
        if (
            $this->maxWidth <= 0
            || $this->maxHeight <= 0
            || $this->thumbnailMaxWidth <= 0
            || $this->thumbnailMaxHeight <= 0
            || $this->webpQuality < 1
            || $this->webpQuality > 100
        ) {
            throw new \InvalidArgumentException('Asset snapshot dimensions and WebP quality are invalid.');
        }
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new \RuntimeException('The GD extension with WebP support is required for asset snapshots.');
        }
    }

    public function generate(CreativeAsset $asset, ?string $sourceBody): AssetSnapshotArtifacts
    {
        if ($asset->id === null || $asset->id <= 0) {
            throw new AssetSnapshotProcessingException('asset_snapshot_identity_invalid', 'Snapshot jobs require a persisted creative asset.', false);
        }

        $verifiedBody = $this->verifiedBody($asset, $sourceBody);
        if ($asset->type === AssetType::Image) {
            $source = $this->imageSource($asset, $verifiedBody);
        } elseif ($asset->type === AssetType::Video) {
            $source = $this->videoFrames->extract(
                $verifiedBody,
                $asset->contentType,
                $this->maxWidth,
                $this->maxHeight,
            );
        } elseif ($asset->type === AssetType::FabricSnapshot) {
            $source = $this->fabricSource($asset, $verifiedBody);
        } else {
            $source = $this->textSource($asset, $verifiedBody);
        }
        $decoded = @imagecreatefromstring($source);
        if (!$decoded instanceof GdImage) {
            throw new AssetSnapshotProcessingException('asset_snapshot_decode_failed', 'Snapshot source could not be decoded as an image.', false);
        }

        try {
            [$full, $width, $height] = $this->scaledImage($decoded, $this->maxWidth, $this->maxHeight);
            try {
                [$thumbnail, $thumbnailWidth, $thumbnailHeight] = $this->scaledImage(
                    $decoded,
                    $this->thumbnailMaxWidth,
                    $this->thumbnailMaxHeight,
                );
                try {
                    $pngBytes = $this->encodePng($full);
                    $webpBytes = $this->encodeWebp($full);
                    $thumbnailWebpBytes = $this->encodeWebp($thumbnail);
                } finally {
                    imagedestroy($thumbnail);
                }
            } finally {
                imagedestroy($full);
            }
        } finally {
            imagedestroy($decoded);
        }

        $keys = $this->objectKeys($asset);

        return new AssetSnapshotArtifacts(
            pngObjectKey: $keys['png'],
            webpObjectKey: $keys['webp'],
            thumbnailWebpObjectKey: $keys['thumbnail'],
            pngBytes: $pngBytes,
            webpBytes: $webpBytes,
            thumbnailWebpBytes: $thumbnailWebpBytes,
            width: $width,
            height: $height,
            thumbnailWidth: $thumbnailWidth,
            thumbnailHeight: $thumbnailHeight,
        );
    }

    private function imageSource(CreativeAsset $asset, string $body): string
    {
        $image = @\getimagesizefromstring($body);
        if (!\is_array($image) || (string) ($image['mime'] ?? '') !== $asset->contentType) {
            throw new AssetSnapshotProcessingException('asset_image_content_changed', 'Image bytes no longer match the confirmed content type.', false);
        }
        if ((int) ($image[0] ?? 0) !== $asset->width || (int) ($image[1] ?? 0) !== $asset->height) {
            throw new AssetSnapshotProcessingException('asset_image_dimensions_changed', 'Image dimensions changed after upload confirmation.', false);
        }

        return $body;
    }

    private function fabricSource(CreativeAsset $asset, string $body): string
    {
        if ($asset->contentType !== 'application/json') {
            throw new AssetSnapshotProcessingException('asset_fabric_content_type_invalid', 'Fabric creative content type must be application/json.', false);
        }

        try {
            $payload = $this->fabricValidator->validate($body);
        } catch (AssetValidationException $exception) {
            throw new AssetSnapshotProcessingException($exception->errorCode, $exception->getMessage(), false, $exception);
        }
        if ($payload->width !== $asset->width || $payload->height !== $asset->height) {
            throw new AssetSnapshotProcessingException('asset_fabric_dimensions_changed', 'Fabric snapshot dimensions changed after upload confirmation.', false);
        }

        return $payload->snapshotBytes;
    }

    private function textSource(CreativeAsset $asset, string $body): string
    {
        if ($asset->contentType !== 'text/plain' || \str_contains($body, "\0")) {
            throw new AssetSnapshotProcessingException('asset_text_content_invalid', 'Text creative content is invalid.', false);
        }

        $image = imagecreatetruecolor(600, 200);
        if (!$image instanceof GdImage) {
            throw new AssetSnapshotProcessingException('asset_text_snapshot_failed', 'Text snapshot canvas could not be created.', true);
        }

        $background = \imagecolorallocate($image, 248, 250, 252);
        $foreground = \imagecolorallocate($image, 15, 23, 42);
        \imagefilledrectangle($image, 0, 0, 599, 199, $background);
        $lines = \explode("\n", \wordwrap(\trim($body), 72, "\n", true));
        foreach (\array_slice($lines, 0, 10) as $index => $line) {
            \imagestring($image, 3, 16, 16 + ($index * 18), \substr($line, 0, 72), $foreground);
        }

        try {
            return $this->encodePng($image);
        } finally {
            imagedestroy($image);
        }
    }

    private function verifiedBody(CreativeAsset $asset, ?string $body): string
    {
        if ($body === null || $body === '') {
            throw new AssetSnapshotProcessingException('asset_snapshot_source_missing', 'Snapshot source bytes are missing.', true);
        }
        if (\strlen($body) !== $asset->byteSize) {
            throw new AssetSnapshotProcessingException(
                'asset_snapshot_source_size_changed',
                'Snapshot source size no longer matches the confirmed asset.',
                false,
            );
        }
        $checksum = 'sha256:' . \hash('sha256', $body);
        if ($asset->checksum === null || !\hash_equals($asset->checksum, $checksum)) {
            throw new AssetSnapshotProcessingException(
                'asset_snapshot_source_checksum_changed',
                'Snapshot source checksum no longer matches the confirmed asset.',
                false,
            );
        }

        return $body;
    }

    /** @return array{0:GdImage, 1:int, 2:int} */
    private function scaledImage(GdImage $source, int $maxWidth, int $maxHeight): array
    {
        $sourceWidth = \imagesx($source);
        $sourceHeight = \imagesy($source);
        $scale = \min(1.0, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $width = (int) \floor($sourceWidth * $scale);
        $height = (int) \floor($sourceHeight * $scale);
        if ($width < 1) {
            $width = 1;
        }
        if ($height < 1) {
            $height = 1;
        }
        $target = imagecreatetruecolor($width, $height);
        if (!$target instanceof GdImage) {
            throw new AssetSnapshotProcessingException('asset_snapshot_canvas_failed', 'Snapshot canvas could not be created.', true);
        }

        \imagealphablending($target, false);
        \imagesavealpha($target, true);
        $transparent = \imagecolorallocatealpha($target, 0, 0, 0, 127);
        \imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        \imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return [$target, $width, $height];
    }

    private function encodePng(GdImage $image): string
    {
        \ob_start();
        $encoded = imagepng($image, null, 6);
        $bytes = ob_get_clean();
        if (!$encoded || !\is_string($bytes) || $bytes === '') {
            throw new AssetSnapshotProcessingException('asset_snapshot_png_encode_failed', 'Snapshot PNG encoding failed.', true);
        }

        return $bytes;
    }

    private function encodeWebp(GdImage $image): string
    {
        \ob_start();
        $encoded = imagewebp($image, null, $this->webpQuality);
        $bytes = ob_get_clean();
        if (!$encoded || !\is_string($bytes) || $bytes === '') {
            throw new AssetSnapshotProcessingException('asset_snapshot_webp_encode_failed', 'Snapshot WebP encoding failed.', true);
        }

        return $bytes;
    }

    /** @return array{png:string, webp:string, thumbnail:string} */
    private function objectKeys(CreativeAsset $asset): array
    {
        $identity = (string) $asset->checksum;
        $digest = \substr(\hash('sha256', $identity . '|' . $asset->id), 0, 24);
        $base = \sprintf('organizations/%d/assets/derived/%d/%s', $asset->organizationId, $asset->id, $digest);
        $keys = [
            'png' => $base . '/snapshot.png',
            'webp' => $base . '/snapshot.webp',
            'thumbnail' => $base . '/thumbnail.webp',
        ];
        foreach ($keys as $key) {
            new AssetObjectKey($key);
        }

        return $keys;
    }
}
