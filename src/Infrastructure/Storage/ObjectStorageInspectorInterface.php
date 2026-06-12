<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

interface ObjectStorageInspectorInterface
{
    public function inspect(string $objectKey): ?StoredObjectInspection;
}
