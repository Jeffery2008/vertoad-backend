<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Infrastructure\Storage\UnavailableAssetObjectStorage;

final class UnavailableAssetObjectStorageTest extends TestCase
{
    public function testReadUsesTheDefaultConfigurationError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Asset object storage is not configured.');

        (new UnavailableAssetObjectStorage())->read('organizations/1/assets/source.png');
    }

    public function testSnapshotWriteUsesTheConfiguredError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage maintenance');

        (new UnavailableAssetObjectStorage('storage maintenance'))->putSnapshot(
            'organizations/1/assets/derived/snapshot.png',
            'bytes',
            'image/png',
        );
    }
}
