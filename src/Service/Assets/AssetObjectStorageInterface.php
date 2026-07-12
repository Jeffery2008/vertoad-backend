<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

interface AssetObjectStorageInterface
{
    public function read(string $objectKey): string;

    public function putSnapshot(string $objectKey, string $body, string $contentType): void;
}
