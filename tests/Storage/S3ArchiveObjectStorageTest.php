<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Storage\S3ArchiveObjectStorage;

final class S3ArchiveObjectStorageTest extends TestCase
{
    public function testPutsAndGetsS3UriObjectsWithConfiguredS3CompatibleClient(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['ObjectURL' => 'https://r2.example/archive/raw/part.parquet']));
        $mock->append(new Result(['Body' => 'PAR1downloaded']));
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
        ]);
        $storage = new S3ArchiveObjectStorage(
            [
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => 'fallback-bucket',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ],
            $client,
        );

        $storage->put('s3://archive/raw/part.parquet', 'PAR1uploaded', 'application/vnd.apache.parquet');
        $putCommand = $mock->getLastCommand();
        self::assertNotNull($putCommand);
        self::assertSame('PutObject', $putCommand->getName());
        self::assertSame('archive', $putCommand['Bucket']);
        self::assertSame('raw/part.parquet', $putCommand['Key']);
        self::assertSame('application/vnd.apache.parquet', $putCommand['ContentType']);

        $body = $storage->get('s3://archive/raw/part.parquet');

        self::assertSame('PAR1downloaded', $body);
    }

    public function testUsesConfiguredBucketForRelativeObjectKeysAndRejectsInvalidConfig(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
        ]);
        $storage = new S3ArchiveObjectStorage([
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'bucket' => 'archive-bucket',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
        ], $client);

        $storage->put('raw/part.parquet', 'PAR1', 'application/vnd.apache.parquet');
        $putCommand = $mock->getLastCommand();

        self::assertNotNull($putCommand);
        self::assertSame('archive-bucket', $putCommand['Bucket']);
        self::assertSame('raw/part.parquet', $putCommand['Key']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 endpoint is required.');

        new S3ArchiveObjectStorage([
            'endpoint' => '',
            'bucket' => 'archive-bucket',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
        ]);
    }

    public function testRejectsMalformedS3Uri(): void
    {
        $storage = new S3ArchiveObjectStorage([
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'bucket' => 'archive-bucket',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive object key must be a relative key or s3://bucket/key URI.');

        $storage->get('s3://archive');
    }

    public function testRejectsMissingBucketAndCredentialsAndAbsoluteUris(): void
    {
        try {
            new S3ArchiveObjectStorage([
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => '',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ]);
            self::fail('Expected missing bucket validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 bucket is required.', $exception->getMessage());
        }

        try {
            new S3ArchiveObjectStorage([
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => 'archive-bucket',
                'access_key_id' => '',
                'secret_access_key' => '',
            ]);
            self::fail('Expected missing credential validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 access key and secret are required.', $exception->getMessage());
        }

        $storage = new S3ArchiveObjectStorage([
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'bucket' => 'archive-bucket',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Archive object key must be a relative key or s3://bucket/key URI.');

        $storage->put('https://archive.example/raw/part.parquet', 'PAR1', 'application/vnd.apache.parquet');
    }
}
