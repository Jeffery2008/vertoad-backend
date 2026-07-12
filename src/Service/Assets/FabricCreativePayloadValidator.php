<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use JsonException;
use VertoAD\Domain\Assets\FabricCreativePayload;

final readonly class FabricCreativePayloadValidator
{
    private const OBJECT_TYPES = ['textbox', 'text', 'itext', 'rect', 'circle', 'ellipse', 'line', 'image'];
    private const FILTER_TYPES = ['brightness', 'contrast', 'grayscale', 'saturation', 'blur'];
    private const SNAPSHOT_CONTENT_TYPES = ['image/png', 'image/webp'];
    private const EMBEDDED_IMAGE_CONTENT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const ROOT_KEYS = ['asset_type', 'render_mode', 'fabric_json', 'resources', 'snapshot'];
    private const FABRIC_ROOT_KEYS = ['version', 'objects', 'background', 'backgroundColor'];
    private const DANGEROUS_KEYS = ['__proto__', 'prototype', 'constructor'];

    public function __construct(
        private ?AssetPublicUrlResolver $publicUrls = null,
        private int $maxObjects = 200,
        private int $maxTextLength = 5000,
        private int $maxEmbeddedImageBytes = 524_288,
        private int $maxSnapshotBytes = 1_048_576,
        private int $maxWidth = 4096,
        private int $maxHeight = 4096,
    ) {
        if (
            $this->maxObjects <= 0
            || $this->maxTextLength <= 0
            || $this->maxEmbeddedImageBytes <= 0
            || $this->maxSnapshotBytes <= 0
            || $this->maxWidth <= 0
            || $this->maxHeight <= 0
        ) {
            throw new \InvalidArgumentException('Fabric creative validation limits must be positive.');
        }
    }

    public function validate(string $body): FabricCreativePayload
    {
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid('Fabric creative must contain valid JSON.');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw $this->invalid('Fabric creative root must be an object.');
        }
        $this->assertNoDangerousKeys($payload);
        if (!$this->hasExactKeys($payload, self::ROOT_KEYS)) {
            throw $this->invalid('Fabric creative root fields are invalid.');
        }
        if (($payload['asset_type'] ?? null) !== 'fabric_ad' || ($payload['render_mode'] ?? null) !== 'fabric-json') {
            throw $this->invalid('Fabric creative type and render mode are invalid.');
        }

        $fabricJson = $payload['fabric_json'] ?? null;
        $objects = is_array($fabricJson) ? ($fabricJson['objects'] ?? null) : null;
        if (!is_array($fabricJson) || !is_array($objects) || !array_is_list($objects)) {
            throw $this->invalid('Fabric creative fabric_json.objects must be an array.');
        }
        if (!$this->hasOnlyKeys($fabricJson, self::FABRIC_ROOT_KEYS)) {
            throw $this->invalid('Fabric creative canvas fields are not supported by the controlled renderer.');
        }
        foreach (['background', 'backgroundColor'] as $backgroundKey) {
            if (isset($fabricJson[$backgroundKey]) && !$this->isSafeColor($fabricJson[$backgroundKey])) {
                throw $this->invalid('Fabric creative canvas background is invalid.');
            }
        }
        if (isset($fabricJson['version']) && (!is_string($fabricJson['version']) || strlen($fabricJson['version']) > 32)) {
            throw $this->invalid('Fabric creative version is invalid.');
        }
        if (count($objects) > $this->maxObjects) {
            throw $this->invalid('Fabric creative contains too many objects.');
        }

        $objectKinds = [];
        foreach ($objects as $object) {
            [$id, $kind] = $this->validateObject($object);
            if (isset($objectKinds[$id])) {
                throw $this->invalid('Fabric creative object identifiers must be unique.');
            }
            $objectKinds[$id] = $kind;
        }
        $this->validateResources($payload['resources'] ?? null, $objectKinds);

        $snapshot = $payload['snapshot'] ?? null;
        if (
            !is_array($snapshot)
            || array_is_list($snapshot)
            || !$this->hasExactKeys($snapshot, ['data_url', 'usage'])
            || ($snapshot['usage'] ?? null) !== 'preview-review-fallback'
        ) {
            throw $this->invalid('Fabric creative snapshot usage is invalid.');
        }

        [$contentType, $snapshotBytes] = $this->decodeDataUrl(
            $snapshot['data_url'] ?? null,
            self::SNAPSHOT_CONTENT_TYPES,
            'Fabric creative snapshot',
            $this->maxSnapshotBytes,
        );
        $image = @getimagesizefromstring($snapshotBytes);
        if (!is_array($image) || (string) ($image['mime'] ?? '') !== $contentType) {
            throw $this->invalid('Fabric creative snapshot bytes do not match the declared image type.');
        }

        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > $this->maxWidth || $height > $this->maxHeight) {
            throw $this->invalid('Fabric creative snapshot dimensions are outside allowed bounds.');
        }

        return new FabricCreativePayload($fabricJson, $contentType, $snapshotBytes, $width, $height);
    }

    /** @return array{0:string, 1:string} */
    private function validateObject(mixed $object): array
    {
        if (!is_array($object) || array_is_list($object)) {
            throw $this->invalid('Fabric creative objects must be JSON objects.');
        }

        $id = $object['id'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $id) !== 1) {
            throw $this->invalid('Fabric creative object identifier is invalid.');
        }
        $type = $object['type'] ?? null;
        if (!is_string($type) || !in_array(strtolower(trim($type)), self::OBJECT_TYPES, true)) {
            throw $this->invalid('Fabric creative contains an unsupported object type.');
        }
        if (array_key_exists('clipPath', $object) && $object['clipPath'] !== null) {
            throw $this->invalid('Fabric creative clip paths are not supported by the controlled renderer.');
        }
        foreach (['fill', 'stroke', 'backgroundColor', 'textBackgroundColor'] as $colorKey) {
            if (array_key_exists($colorKey, $object) && !$this->isSafeColor($object[$colorKey])) {
                throw $this->invalid('Fabric creative object colors must be static CSS colors.');
            }
        }
        foreach (['resizeFilter', 'path', 'eraser'] as $enlivenedKey) {
            if (array_key_exists($enlivenedKey, $object) && $object[$enlivenedKey] !== null) {
                throw $this->invalid('Fabric creative contains an unsupported enlivened object property.');
            }
        }

        $normalizedType = strtolower(trim($type));
        if (in_array($normalizedType, ['textbox', 'text', 'itext'], true)) {
            $text = $object['text'] ?? null;
            if (!is_string($text) || strlen($text) > $this->maxTextLength) {
                throw $this->invalid('Fabric creative text is invalid or too long.');
            }
        }

        if ($normalizedType === 'image') {
            $source = $object['src'] ?? null;
            if (!is_string($source) || !$this->isSafeImageSource($source)) {
                throw $this->invalid('Fabric creative image source is not allowed.');
            }
        }

        $filters = $object['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters)) {
            throw $this->invalid('Fabric creative image filters must be an array.');
        }
        foreach ($filters as $filter) {
            $filterType = is_array($filter) && !array_is_list($filter) ? ($filter['type'] ?? null) : null;
            if (!is_string($filterType) || !in_array(strtolower(trim($filterType)), self::FILTER_TYPES, true)) {
                throw $this->invalid('Fabric creative contains an unsupported image filter.');
            }
        }

        $kind = match ($normalizedType) {
            'textbox', 'text', 'itext' => 'text',
            'image' => 'image',
            default => 'shape',
        };

        return [$id, $kind];
    }

    /** @param array<string, string> $objectKinds */
    private function validateResources(mixed $resources, array $objectKinds): void
    {
        if (!is_array($resources) || !array_is_list($resources)) {
            throw $this->invalid('Fabric creative resources must be an array.');
        }

        $resourceIds = [];
        foreach ($resources as $resource) {
            if (
                !is_array($resource)
                || array_is_list($resource)
                || !$this->hasExactKeys($resource, ['id', 'kind', 'policy'])
                || !is_string($resource['id'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', (string) $resource['id']) !== 1
                || !is_string($resource['kind'] ?? null)
                || !in_array($resource['kind'], ['text', 'shape', 'image'], true)
                || ($resource['policy'] ?? null) !== 'platform-controlled'
            ) {
                throw $this->invalid('Fabric creative resource declarations are invalid.');
            }

            $id = (string) $resource['id'];
            if (isset($resourceIds[$id])) {
                throw $this->invalid('Fabric creative resource identifiers must be unique.');
            }
            if (!isset($objectKinds[$id]) || $objectKinds[$id] !== $resource['kind']) {
                throw $this->invalid('Fabric creative resources must match controlled renderer objects.');
            }
            $resourceIds[$id] = true;
        }

        if (count($resourceIds) !== count($objectKinds)) {
            throw $this->invalid('Fabric creative resources must declare every renderer object.');
        }
    }

    private function isSafeImageSource(string $source): bool
    {
        if (str_starts_with($source, 'data:')) {
            try {
                [, $bytes] = $this->decodeDataUrl(
                    $source,
                    self::EMBEDDED_IMAGE_CONTENT_TYPES,
                    'Fabric creative embedded image',
                    $this->maxEmbeddedImageBytes,
                );
            } catch (AssetValidationException) {
                return false;
            }

            $image = @getimagesizefromstring($bytes);

            return is_array($image) && ($image['mime'] ?? null) === $this->dataUrlContentType($source);
        }

        return $this->publicUrls?->isTrustedUrl($source) === true;
    }

    /**
     * @param list<string> $allowedContentTypes
     * @return array{0:string, 1:string}
     */
    private function decodeDataUrl(mixed $value, array $allowedContentTypes, string $label, int $maxBytes): array
    {
        if (!is_string($value) || preg_match('/^data:([^;,]+);base64,([A-Za-z0-9+\/=]+)$/D', $value, $matches) !== 1) {
            throw $this->invalid($label . ' must be a strict base64 data URL.');
        }

        $contentType = $matches[1];
        $bytes = base64_decode($matches[2], true);
        if (
            !in_array($contentType, $allowedContentTypes, true)
            || $bytes === false
            || $bytes === ''
            || strlen($bytes) > $maxBytes
            || base64_encode($bytes) !== $matches[2]
        ) {
            throw $this->invalid($label . ' content type or base64 payload is invalid.');
        }

        return [$contentType, $bytes];
    }

    /** @param array<mixed> $value */
    private function assertNoDangerousKeys(array $value): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::DANGEROUS_KEYS, true)) {
                throw $this->invalid('Fabric creative contains a forbidden object key.');
            }
            if (is_array($child)) {
                $this->assertNoDangerousKeys($child);
            }
        }
    }

    private function dataUrlContentType(string $source): string
    {
        $separator = strpos($source, ';');

        return $separator === false ? '' : substr($source, 5, $separator - 5);
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /** @param array<mixed> $value @param list<string> $allowed */
    private function hasOnlyKeys(array $value, array $allowed): bool
    {
        return array_diff(array_keys($value), $allowed) === [];
    }

    private function isSafeColor(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value)
            && strlen($value) <= 64
            && preg_match('/^(?:#[0-9a-fA-F]{3,8}|rgba?\([0-9.,% ]+\)|hsla?\([0-9.,% a-zA-Z]+\)|transparent)$/D', $value) === 1;
    }

    private function invalid(string $message): AssetValidationException
    {
        return new AssetValidationException('asset_fabric_payload_invalid', $message);
    }
}
