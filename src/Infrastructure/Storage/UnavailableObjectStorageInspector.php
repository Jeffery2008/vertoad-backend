<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

final class UnavailableObjectStorageInspector implements ObjectStorageInspectorInterface
{
    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        return null;
    }
}
