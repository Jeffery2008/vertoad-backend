<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use InvalidArgumentException;
use RuntimeException;

final readonly class BackupSourceRegistry
{
    public const ASSETS = 'assets';
    public const WITHDRAWAL_PROOFS = 'withdrawal_proofs';
    public const ARCHIVE = 'archive';

    /** @var array<string, BackupObjectStorageInterface> */
    private array $storages;

    /** @param array<string, BackupObjectStorageInterface> $storages */
    public function __construct(array $storages)
    {
        $normalized = [];
        foreach ($storages as $sourceStorage => $storage) {
            $sourceStorage = trim((string) $sourceStorage);
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $sourceStorage)) {
                throw new InvalidArgumentException('Backup source storage identifier is invalid.');
            }
            if (!$storage instanceof BackupObjectStorageInterface) {
                throw new InvalidArgumentException('Backup source storage must implement BackupObjectStorageInterface.');
            }
            $normalized[$sourceStorage] = $storage;
        }

        $this->storages = $normalized;
    }

    public function storageFor(string $sourceStorage, BackupObjectStorageInterface $targetStorage): BackupObjectStorageInterface
    {
        if (!isset($this->storages[$sourceStorage])) {
            throw new RuntimeException('Backup source storage is not configured: ' . $sourceStorage);
        }
        $sourceStorageInstance = $this->storages[$sourceStorage];
        if ($sourceStorageInstance === $targetStorage) {
            throw new RuntimeException('Backup source storage must be separate from the BACKUP target: ' . $sourceStorage);
        }

        return $sourceStorageInstance;
    }
}
