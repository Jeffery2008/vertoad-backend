<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

use InvalidArgumentException;

final readonly class AssetSnapshotArtifacts
{
    public function __construct(
        public string $pngObjectKey,
        public string $webpObjectKey,
        public string $thumbnailWebpObjectKey,
        public string $pngBytes,
        public string $webpBytes,
        public string $thumbnailWebpBytes,
        public int $width,
        public int $height,
        public int $thumbnailWidth,
        public int $thumbnailHeight,
    ) {
        new AssetObjectKey($this->pngObjectKey);
        new AssetObjectKey($this->webpObjectKey);
        new AssetObjectKey($this->thumbnailWebpObjectKey);
        if (count(array_unique([
            $this->pngObjectKey,
            $this->webpObjectKey,
            $this->thumbnailWebpObjectKey,
        ])) !== 3) {
            throw new InvalidArgumentException('Asset snapshot artifact object keys must be distinct.');
        }
        if (!$this->matchesImage($this->pngBytes, 'image/png')) {
            throw new InvalidArgumentException('Asset snapshot PNG bytes are invalid.');
        }
        if (
            !$this->matchesImage($this->webpBytes, 'image/webp')
            || !$this->matchesImage($this->thumbnailWebpBytes, 'image/webp')
        ) {
            throw new InvalidArgumentException('Asset snapshot WebP bytes are invalid.');
        }
        if (
            $this->width <= 0
            || $this->height <= 0
            || $this->thumbnailWidth <= 0
            || $this->thumbnailHeight <= 0
            || $this->thumbnailWidth > $this->width
            || $this->thumbnailHeight > $this->height
        ) {
            throw new InvalidArgumentException('Asset snapshot artifact dimensions are invalid.');
        }
    }

    private function matchesImage(string $bytes, string $contentType): bool
    {
        $image = $bytes === '' ? false : @getimagesizefromstring($bytes);

        return is_array($image) && ($image['mime'] ?? null) === $contentType;
    }
}
