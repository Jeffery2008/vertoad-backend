<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

interface BackupObjectStorageInterface
{
    public function putFile(string $objectKey, string $localPath, string $contentType): void;

    public function putString(string $objectKey, string $body, string $contentType): void;

    public function getFile(string $objectKey, string $localPath): void;

    public function readString(string $objectKey): string;

    public function copy(string $sourceObjectKey, string $destinationObjectKey): void;

    public function exists(string $objectKey): bool;

    public function size(string $objectKey): int;

    public function sha256(string $objectKey): string;
}
