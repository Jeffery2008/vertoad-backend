<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

use InvalidArgumentException;

final readonly class AssetObjectKey
{
    /** @var non-empty-list<string> */
    private array $segments;

    public function __construct(public string $value)
    {
        if ($value !== trim($value) || $value === '' || strlen($value) > 512) {
            throw new InvalidArgumentException('Asset object key must be a non-empty relative key of at most 512 bytes.');
        }
        if (str_starts_with($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Asset object key contains an unsafe path character.');
        }

        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Asset object key contains an unsafe path segment.');
            }
        }

        $this->segments = $segments;
    }

    public function encodedPath(): string
    {
        return implode('/', array_map('rawurlencode', $this->segments));
    }
}
