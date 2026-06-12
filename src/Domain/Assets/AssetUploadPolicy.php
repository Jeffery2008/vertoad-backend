<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

use InvalidArgumentException;

final readonly class AssetUploadPolicy
{
    /**
     * @param array<string, true> $blockedExtensions
     * @param array<string, true> $blockedContentTypes
     * @param array<string, AssetUploadTypePolicy> $typePolicies
     */
    private function __construct(
        public int $uploadIntentTtlSeconds,
        private array $blockedExtensions,
        private array $blockedContentTypes,
        private array $typePolicies,
    ) {
    }

    public static function default(): self
    {
        return self::fromArray([
            'upload_intent_ttl_seconds' => 900,
            'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
            'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
            'types' => [
                'image' => [
                    'max_bytes' => 10_485_760,
                    'max_width' => 4096,
                    'max_height' => 4096,
                    'allowed_content_types' => [
                        'png' => 'image/png',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                    ],
                    'magic_signatures' => [
                        'image/png' => [['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")]],
                        'image/jpeg' => [['prefix_base64' => base64_encode("\xFF\xD8\xFF")]],
                        'image/gif' => [
                            ['prefix_ascii' => 'GIF87a'],
                            ['prefix_ascii' => 'GIF89a'],
                        ],
                        'image/webp' => [
                            ['prefix_ascii' => 'RIFF', 'offset_ascii' => ['offset' => 8, 'value' => 'WEBP']],
                        ],
                    ],
                ],
                'video' => [
                    'max_bytes' => 209_715_200,
                    'max_width' => 3840,
                    'max_height' => 2160,
                    'max_duration_seconds' => 120.0,
                    'allowed_content_types' => [
                        'mp4' => 'video/mp4',
                        'webm' => 'video/webm',
                    ],
                    'magic_signatures' => [
                        'video/mp4' => [['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']]],
                        'video/webm' => [['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")]],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [
                            ['trimmed_prefix_ascii' => '{'],
                            ['trimmed_prefix_ascii' => '['],
                        ],
                    ],
                ],
                'text' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [['forbid_ascii_ci' => '<script']],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $ttlSeconds = self::positiveInt($value, 'upload_intent_ttl_seconds', minimum: 60);
        $blockedExtensions = self::normalizedStringSet($value, 'blocked_extensions');
        $blockedContentTypes = self::normalizedStringSet($value, 'blocked_content_types');
        $types = $value['types'] ?? null;
        if (!is_array($types)) {
            throw new InvalidArgumentException('Asset upload policy types must be an object.');
        }

        $typePolicies = [];
        foreach (AssetType::cases() as $assetType) {
            $typeValue = $types[$assetType->value] ?? null;
            if (!is_array($typeValue)) {
                throw new InvalidArgumentException('Asset upload policy is missing type ' . $assetType->value . '.');
            }

            $typePolicies[$assetType->value] = self::typePolicy($assetType, $typeValue);
        }

        return new self($ttlSeconds, $blockedExtensions, $blockedContentTypes, $typePolicies);
    }

    public function isBlockedExtension(string $extension): bool
    {
        return isset($this->blockedExtensions[strtolower(trim($extension))]);
    }

    public function isBlockedContentType(string $contentType): bool
    {
        return isset($this->blockedContentTypes[strtolower(trim($contentType))]);
    }

    /**
     * @return array<string, string>
     */
    public function allowedContentTypes(AssetType $type): array
    {
        return $this->policyFor($type)->allowedContentTypes;
    }

    public function maxBytes(AssetType $type): int
    {
        return $this->policyFor($type)->maxBytes;
    }

    public function maxWidth(AssetType $type): ?int
    {
        return $this->policyFor($type)->maxWidth;
    }

    public function maxHeight(AssetType $type): ?int
    {
        return $this->policyFor($type)->maxHeight;
    }

    public function maxDurationSeconds(AssetType $type): ?float
    {
        return $this->policyFor($type)->maxDurationSeconds;
    }

    public function matchesMagic(string $contentType, string $bytes): bool
    {
        $contentType = strtolower(trim($contentType));

        foreach ($this->typePolicies as $policy) {
            foreach ($policy->magicSignatures[$contentType] ?? [] as $signature) {
                if ($signature->matches($bytes)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function policyFor(AssetType $type): AssetUploadTypePolicy
    {
        return $this->typePolicies[$type->value];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function typePolicy(AssetType $assetType, array $value): AssetUploadTypePolicy
    {
        $maxBytes = self::positiveInt($value, 'max_bytes');
        $maxWidth = in_array($assetType, [AssetType::Image, AssetType::Video], true)
            ? self::positiveInt($value, 'max_width')
            : null;
        $maxHeight = in_array($assetType, [AssetType::Image, AssetType::Video], true)
            ? self::positiveInt($value, 'max_height')
            : null;
        $maxDurationSeconds = $assetType === AssetType::Video
            ? self::positiveNumber($value, 'max_duration_seconds')
            : null;
        $allowedContentTypes = self::allowedContentTypesValue($value);
        $magicSignatures = self::magicSignaturesValue($value, $allowedContentTypes);

        return new AssetUploadTypePolicy(
            $maxBytes,
            $maxWidth,
            $maxHeight,
            $maxDurationSeconds,
            $allowedContentTypes,
            $magicSignatures,
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function positiveInt(array $value, string $key, int $minimum = 1): int
    {
        $candidate = $value[$key] ?? null;
        if (!is_int($candidate) || $candidate < $minimum) {
            throw new InvalidArgumentException('Asset upload policy ' . $key . ' must be an integer >= ' . $minimum . '.');
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function positiveNumber(array $value, string $key): float
    {
        $candidate = $value[$key] ?? null;
        if ((!is_float($candidate) && !is_int($candidate)) || $candidate <= 0) {
            throw new InvalidArgumentException('Asset upload policy ' . $key . ' must be positive.');
        }

        return (float) $candidate;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, true>
     */
    private static function normalizedStringSet(array $value, string $key): array
    {
        $items = $value[$key] ?? null;
        if (!is_array($items)) {
            throw new InvalidArgumentException('Asset upload policy ' . $key . ' must be an array.');
        }

        $set = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('Asset upload policy ' . $key . ' contains an invalid value.');
            }

            $set[strtolower(trim($item))] = true;
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, string>
     */
    private static function allowedContentTypesValue(array $value): array
    {
        $items = $value['allowed_content_types'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new InvalidArgumentException('Asset upload policy allowed_content_types must be a non-empty object.');
        }

        $allowed = [];
        foreach ($items as $extension => $contentType) {
            if (!is_string($extension) || trim($extension) === '' || !is_string($contentType) || trim($contentType) === '') {
                throw new InvalidArgumentException('Asset upload policy allowed_content_types contains an invalid mapping.');
            }

            $allowed[strtolower(trim($extension))] = strtolower(trim($contentType));
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, string> $allowedContentTypes
     * @return array<string, list<AssetMagicSignature>>
     */
    private static function magicSignaturesValue(array $value, array $allowedContentTypes): array
    {
        $items = $value['magic_signatures'] ?? null;
        if (!is_array($items)) {
            throw new InvalidArgumentException('Asset upload policy magic_signatures must be an object.');
        }

        $signatures = [];
        foreach (array_unique(array_values($allowedContentTypes)) as $contentType) {
            $rules = $items[$contentType] ?? null;
            if (!is_array($rules) || $rules === []) {
                throw new InvalidArgumentException('Asset upload policy missing magic signatures for ' . $contentType . '.');
            }

            $signatures[$contentType] = array_map(
                static fn (mixed $rule): AssetMagicSignature => AssetMagicSignature::fromArray($rule),
                $rules,
            );
        }

        return $signatures;
    }
}

final readonly class AssetUploadTypePolicy
{
    /**
     * @param array<string, string> $allowedContentTypes
     * @param array<string, list<AssetMagicSignature>> $magicSignatures
     */
    public function __construct(
        public int $maxBytes,
        public ?int $maxWidth,
        public ?int $maxHeight,
        public ?float $maxDurationSeconds,
        public array $allowedContentTypes,
        public array $magicSignatures,
    ) {
    }
}

final readonly class AssetMagicSignature
{
    /**
     * @param list<array{offset:int, value:string}> $offsetAscii
     */
    private function __construct(
        private ?string $prefix,
        private ?string $trimmedPrefix,
        private ?string $forbiddenAsciiCi,
        private array $offsetAscii,
    ) {
    }

    /**
     * @param mixed $value
     */
    public static function fromArray(mixed $value): self
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Asset upload policy magic signature must be an object.');
        }

        $prefix = null;
        if (isset($value['prefix_base64'])) {
            if (!is_string($value['prefix_base64'])) {
                throw new InvalidArgumentException('Asset upload policy prefix_base64 must be a string.');
            }

            $prefix = base64_decode($value['prefix_base64'], true);
            if ($prefix === false || $prefix === '') {
                throw new InvalidArgumentException('Asset upload policy prefix_base64 is invalid.');
            }
        }

        if (isset($value['prefix_ascii'])) {
            if (!is_string($value['prefix_ascii']) || $value['prefix_ascii'] === '') {
                throw new InvalidArgumentException('Asset upload policy prefix_ascii must be a non-empty string.');
            }

            $prefix = $value['prefix_ascii'];
        }

        $trimmedPrefix = null;
        if (isset($value['trimmed_prefix_ascii'])) {
            if (!is_string($value['trimmed_prefix_ascii']) || $value['trimmed_prefix_ascii'] === '') {
                throw new InvalidArgumentException('Asset upload policy trimmed_prefix_ascii must be a non-empty string.');
            }

            $trimmedPrefix = $value['trimmed_prefix_ascii'];
        }

        $forbiddenAsciiCi = null;
        if (isset($value['forbid_ascii_ci'])) {
            if (!is_string($value['forbid_ascii_ci']) || $value['forbid_ascii_ci'] === '') {
                throw new InvalidArgumentException('Asset upload policy forbid_ascii_ci must be a non-empty string.');
            }

            $forbiddenAsciiCi = strtolower($value['forbid_ascii_ci']);
        }

        $offsetAscii = [];
        if (isset($value['offset_ascii'])) {
            if (!is_array($value['offset_ascii'])) {
                throw new InvalidArgumentException('Asset upload policy offset_ascii must be an object.');
            }

            $offset = $value['offset_ascii']['offset'] ?? null;
            $offsetValue = $value['offset_ascii']['value'] ?? null;
            if (!is_int($offset) || $offset < 0 || !is_string($offsetValue) || $offsetValue === '') {
                throw new InvalidArgumentException('Asset upload policy offset_ascii contains an invalid value.');
            }

            $offsetAscii[] = ['offset' => $offset, 'value' => $offsetValue];
        }

        if ($prefix === null && $trimmedPrefix === null && $forbiddenAsciiCi === null && $offsetAscii === []) {
            throw new InvalidArgumentException('Asset upload policy magic signature must contain a matcher.');
        }

        return new self($prefix, $trimmedPrefix, $forbiddenAsciiCi, $offsetAscii);
    }

    public function matches(string $bytes): bool
    {
        if ($this->prefix !== null && !str_starts_with($bytes, $this->prefix)) {
            return false;
        }

        if ($this->trimmedPrefix !== null && !str_starts_with(ltrim($bytes), $this->trimmedPrefix)) {
            return false;
        }

        if ($this->forbiddenAsciiCi !== null && str_contains(strtolower(substr($bytes, 0, 256)), $this->forbiddenAsciiCi)) {
            return false;
        }

        foreach ($this->offsetAscii as $matcher) {
            if (substr($bytes, $matcher['offset'], strlen($matcher['value'])) !== $matcher['value']) {
                return false;
            }
        }

        return true;
    }
}
