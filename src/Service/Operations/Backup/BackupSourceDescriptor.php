<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use InvalidArgumentException;

final readonly class BackupSourceDescriptor
{
    public string $sourceStorage;
    public string $sourceKey;
    public string $contentType;

    public function __construct(string $sourceStorage, string $sourceKey, string $contentType)
    {
        $sourceStorage = trim($sourceStorage);
        $sourceKey = trim($sourceKey);
        $contentType = strtolower(trim($contentType));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $sourceStorage)) {
            throw new InvalidArgumentException('Backup source storage identifier is invalid.');
        }
        if ($sourceKey === '') {
            throw new InvalidArgumentException('Backup source object key is required.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/', $contentType)) {
            throw new InvalidArgumentException('Backup source content type is invalid.');
        }

        $this->sourceStorage = $sourceStorage;
        $this->sourceKey = $sourceKey;
        $this->contentType = $contentType;
    }
}
