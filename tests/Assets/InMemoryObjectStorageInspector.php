<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageAssetFinalizerInterface;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;

final class InMemoryObjectStorageInspector implements ObjectStorageInspectorInterface, ObjectStorageAssetFinalizerInterface
{
    /** @var array<string, StoredObjectInspection> */
    private array $objects = [];

    /** @var array<string, int> */
    private array $deleteFailures = [];

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

    public function writeFinalFromValidatedBytes(
        StoredObjectInspection $stagingObject,
        string $finalObjectKey,
    ): StoredObjectInspection {
        if ($stagingObject->body === null || $stagingObject->body === '') {
            throw new \RuntimeException('Staging object bytes are missing.');
        }

        $copied = new StoredObjectInspection(
            objectKey: $finalObjectKey,
            contentType: $stagingObject->contentType,
            byteSize: $stagingObject->byteSize,
            width: $stagingObject->width,
            height: $stagingObject->height,
            durationSeconds: $stagingObject->durationSeconds,
            checksum: $stagingObject->checksum,
            leadingBytes: $stagingObject->leadingBytes,
            body: $stagingObject->body,
        );
        $this->objects[$finalObjectKey] = $copied;

        return $copied;
    }

    public function delete(string $objectKey): void
    {
        $remainingFailures = $this->deleteFailures[$objectKey] ?? 0;
        if ($remainingFailures > 0) {
            $this->deleteFailures[$objectKey] = $remainingFailures - 1;
            throw new \RuntimeException('Synthetic object deletion failure.');
        }

        unset($this->objects[$objectKey]);
    }

    public function failNextDelete(string $objectKey): void
    {
        $this->deleteFailures[$objectKey] = ($this->deleteFailures[$objectKey] ?? 0) + 1;
    }
}
