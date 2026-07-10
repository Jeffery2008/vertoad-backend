<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use RuntimeException;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;

final readonly class UnavailableBackupObjectStorage implements BackupObjectStorageInterface
{
    public function __construct(private string $message = 'Backup object storage is not configured.')
    {
    }

    public function putFile(string $objectKey, string $localPath, string $contentType): void
    {
        throw new RuntimeException($this->message);
    }

    public function putString(string $objectKey, string $body, string $contentType): void
    {
        throw new RuntimeException($this->message);
    }

    public function getFile(string $objectKey, string $localPath): void
    {
        throw new RuntimeException($this->message);
    }

    public function readString(string $objectKey): string
    {
        throw new RuntimeException($this->message);
    }

    public function copy(string $sourceObjectKey, string $destinationObjectKey): void
    {
        throw new RuntimeException($this->message);
    }

    public function exists(string $objectKey): bool
    {
        return false;
    }

    public function size(string $objectKey): int
    {
        throw new RuntimeException($this->message);
    }

    public function sha256(string $objectKey): string
    {
        throw new RuntimeException($this->message);
    }
}
