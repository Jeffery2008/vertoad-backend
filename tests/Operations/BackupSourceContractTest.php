<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use VertoAD\Infrastructure\Storage\UnavailableBackupObjectStorage;
use VertoAD\Service\Operations\Backup\BackupSourceDescriptor;
use VertoAD\Service\Operations\Backup\BackupSourceRegistry;

final class BackupSourceContractTest extends TestCase
{
    public function testDescriptorNormalizesTrustedSourceMetadata(): void
    {
        $descriptor = new BackupSourceDescriptor(' assets ', ' assets/banner.png ', ' Image/PNG ');

        self::assertSame('assets', $descriptor->sourceStorage);
        self::assertSame('assets/banner.png', $descriptor->sourceKey);
        self::assertSame('image/png', $descriptor->contentType);
    }

    #[DataProvider('invalidDescriptorProvider')]
    public function testDescriptorRejectsInvalidSourceMetadata(
        string $sourceStorage,
        string $sourceKey,
        string $contentType,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new BackupSourceDescriptor($sourceStorage, $sourceKey, $contentType);
    }

    /** @return iterable<string, array{string,string,string,string}> */
    public static function invalidDescriptorProvider(): iterable
    {
        yield 'storage identifier' => ['Assets!', 'asset.png', 'image/png', 'storage identifier'];
        yield 'blank source key' => ['assets', ' ', 'image/png', 'object key is required'];
        yield 'invalid MIME type' => ['assets', 'asset.png', 'not-a-mime', 'content type is invalid'];
    }

    public function testRegistryReturnsConfiguredSourceAndRejectsUnsafeRegistrations(): void
    {
        $source = new UnavailableBackupObjectStorage('source unavailable');
        $target = new UnavailableBackupObjectStorage('target unavailable');
        $registry = new BackupSourceRegistry([' assets ' => $source]);

        self::assertSame($source, $registry->storageFor(BackupSourceRegistry::ASSETS, $target));

        try {
            new BackupSourceRegistry(['Assets!' => $source]);
            self::fail('Invalid source storage identifiers must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('storage identifier', $exception->getMessage());
        }

        try {
            new BackupSourceRegistry(['assets' => new stdClass()]);
            self::fail('Source storage implementations must satisfy the backup storage contract.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must implement', $exception->getMessage());
        }

        try {
            (new BackupSourceRegistry([]))->storageFor(BackupSourceRegistry::ASSETS, $target);
            self::fail('Missing source storage must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }

        try {
            $registry->storageFor(BackupSourceRegistry::ASSETS, $source);
            self::fail('A source storage instance must not be reused as the backup target.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('separate from the BACKUP target', $exception->getMessage());
        }
    }
}
