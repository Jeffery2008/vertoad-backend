<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use GdImage;
use RuntimeException;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\CreativeAsset;

final class AssetTestFixtures
{
    public static function image(string $contentType = 'image/png', int $width = 4, int $height = 3): string
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Unable to create asset test image.');
        }
        $color = imagecolorallocate($image, 14, 116, 144);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
        ob_start();
        $encoded = match ($contentType) {
            'image/png' => imagepng($image),
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/webp' => imagewebp($image, null, 90),
            default => false,
        };
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (!$encoded || !is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to encode asset test image.');
        }

        return $bytes;
    }

    /** @param list<array<string, mixed>> $objects @param list<array<string, mixed>>|null $resources */
    public static function fabric(array $objects = [], ?array $resources = null, ?string $snapshot = null): string
    {
        $resources ??= array_map(static function (array $object): array {
            $type = strtolower((string) ($object['type'] ?? ''));

            return [
                'id' => (string) ($object['id'] ?? ''),
                'kind' => str_contains($type, 'text') ? 'text' : ($type === 'image' ? 'image' : 'shape'),
                'policy' => 'platform-controlled',
            ];
        }, $objects);

        return json_encode([
            'asset_type' => 'fabric_ad',
            'render_mode' => 'fabric-json',
            'fabric_json' => ['version' => '7.4.0', 'backgroundColor' => '#f8fafc', 'objects' => $objects],
            'resources' => $resources,
            'snapshot' => [
                'data_url' => $snapshot ?? self::dataUrl('image/png', self::image()),
                'usage' => 'preview-review-fallback',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function dataUrl(string $contentType, string $bytes): string
    {
        return 'data:' . $contentType . ';base64,' . base64_encode($bytes);
    }

    public static function asset(
        AssetType $type = AssetType::Image,
        ?string $body = null,
        int $id = 11,
        int $organizationId = 99,
        ?string $contentType = null,
        int $width = 4,
        int $height = 3,
        ?float $durationSeconds = null,
    ): CreativeAsset {
        $body ??= self::image();
        $contentType ??= match ($type) {
            AssetType::Image => 'image/png',
            AssetType::Video => 'video/mp4',
            AssetType::FabricSnapshot => 'application/json',
            AssetType::Text => 'text/plain',
        };

        return new CreativeAsset(
            id: $id,
            uploadIntentId: 7,
            organizationId: $organizationId,
            uploaderUserId: 5,
            type: $type,
            objectKey: 'organizations/' . $organizationId . '/assets/source.bin',
            contentType: $contentType,
            byteSize: strlen($body),
            width: $width,
            height: $height,
            durationSeconds: $durationSeconds,
            checksum: 'sha256:' . hash('sha256', $body),
            status: AssetStatus::PendingReview,
        );
    }
}
