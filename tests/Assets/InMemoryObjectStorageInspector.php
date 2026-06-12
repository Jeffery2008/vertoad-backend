<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;

final class InMemoryObjectStorageInspector implements ObjectStorageInspectorInterface
{
    /** @var array<string, StoredObjectInspection> */
    private array $objects = [];

    /**
     * @param list<StoredObjectInspection> $objects
     */
    public function __construct(array $objects = [])
    {
        foreach ($objects as $object) {
            $this->put($object);
        }
    }

    public function put(StoredObjectInspection $object): void
    {
        $this->objects[$object->objectKey] = $object;
    }

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        return $this->objects[$objectKey] ?? null;
    }
}
