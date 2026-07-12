<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use RuntimeException;
use VertoAD\Service\Assets\AssetObjectStorageInterface;

final readonly class UnavailableAssetObjectStorage implements AssetObjectStorageInterface
{
    public function __construct(private string $message = 'Asset object storage is not configured.')
    {
    }

    public function read(string $objectKey): string
    {
        throw new RuntimeException($this->message);
    }

    public function putSnapshot(string $objectKey, string $body, string $contentType): void
    {
        throw new RuntimeException($this->message);
    }
}
