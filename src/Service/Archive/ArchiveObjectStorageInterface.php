<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

interface ArchiveObjectStorageInterface
{
    public function put(string $objectKey, string $body, string $contentType): void;

    public function get(string $objectKey): string;
}
