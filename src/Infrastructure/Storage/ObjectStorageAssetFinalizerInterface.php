<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

interface ObjectStorageAssetFinalizerInterface extends ObjectStorageInspectorInterface
{
    /**
     * Computes the checksum from the complete stored object. Implementations
     * may omit body to avoid retaining a second full asset in memory.
     */
    public function inspect(string $objectKey): ?StoredObjectInspection;

    /**
     * Writes the already inspected bytes to a server-only final key and
     * returns an authoritative inspection of the written object. The staging
     * object is retained until the caller commits its database transaction.
     * The caller supplies a checksum-bound key, so implementations must not
     * depend on provider-specific conditional PUT support for immutability.
     */
    public function writeFinalFromValidatedBytes(
        StoredObjectInspection $stagingObject,
        string $finalObjectKey,
    ): StoredObjectInspection;

    /** The operation must be idempotent when the object does not exist. */
    public function delete(string $objectKey): void;
}
