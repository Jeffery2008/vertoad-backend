<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use VertoAD\Infrastructure\Storage\S3AssetObjectStorage;

final class S3AssetObjectStorageBoundaryTest extends TestCase
{
    public function testRejectsOversizedStringBodyAndEmptyStream(): void
    {
        $oversizedString = new MockHandler();
        $oversizedString->append(new Result(['ContentLength' => 1]));
        $oversizedString->append(new Result(['Body' => 'xx']));
        $this->assertRuntimeMessage(
            fn () => $this->storage($oversizedString, 1)->read('organizations/99/assets/final/source/string.bin'),
            'Asset object storage object exceeds the configured read limit.',
        );

        $emptyStream = $this->createStub(StreamInterface::class);
        $emptyStream->method('eof')->willReturn(false);
        $emptyStream->method('read')->willReturn('');
        $empty = new MockHandler();
        $empty->append(new Result(['ContentLength' => 1]));
        $empty->append(new Result(['Body' => $emptyStream]));
        $this->assertRuntimeMessage(
            fn () => $this->storage($empty, 1)->read('organizations/99/assets/final/source/empty.bin'),
            'Asset object length changed while it was being read.',
        );
    }

    private function storage(MockHandler $handler, int $maxReadBytes): S3AssetObjectStorage
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://assets.example.test',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $handler,
            'retries' => 0,
        ]);

        return new S3AssetObjectStorage([
            'endpoint' => 'https://assets.example.test',
            'region' => 'auto',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'max_read_bytes' => $maxReadBytes,
            'snapshot_cache_control' => 'public, max-age=31536000, immutable',
            'path_style_endpoint' => true,
        ], $client);
    }

    private function assertRuntimeMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected asset object storage read to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
