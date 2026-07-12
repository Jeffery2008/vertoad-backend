<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Infrastructure\Storage\S3BackupObjectStorage;

final class S3BackupObjectStorageTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-s3-backup-' . bin2hex(random_bytes(5));
        mkdir($this->tempDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->tempDirectory);
    }

    public function testWritesReadsCopiesAndMeasuresEncryptedObjects(): void
    {
        $putFile = null;
        $putString = null;
        $copy = null;
        $mock = new MockHandler();
        $mock->append(static function (CommandInterface $command) use (&$putFile): Result {
            $putFile = $command;

            return new Result([]);
        });
        $mock->append(new Result(['ServerSideEncryption' => 'AES256']));
        $mock->append(static function (CommandInterface $command) use (&$putString): Result {
            $putString = $command;

            return new Result([]);
        });
        $mock->append(new Result(['ServerSideEncryption' => 'AES256']));
        $mock->append(new Result(['Body' => 'downloaded-file']));
        $mock->append(new Result(['Body' => 'downloaded-string']));
        $mock->append(static function (CommandInterface $command) use (&$copy): Result {
            $copy = $command;

            return new Result([]);
        });
        $mock->append(new Result(['ServerSideEncryption' => 'AES256']));
        $mock->append(new Result(['ContentLength' => 17]));
        $mock->append(new Result(['ContentLength' => 17]));
        $mock->append(new Result(['Body' => Utils::streamFor('hash-me')]));
        $storage = $this->storage($mock);
        $source = $this->tempDirectory . DIRECTORY_SEPARATOR . 'source.sql';
        file_put_contents($source, 'select 1;');

        $storage->putFile('backups/db.sql', $source, 'application/sql');
        self::assertSame('AES256', $putFile?->offsetGet('ServerSideEncryption'));
        self::assertSame('backup-bucket', $putFile?->offsetGet('Bucket'));

        $storage->putString('s3://other-bucket/backups/config.json', '{}', 'application/json');
        self::assertSame('other-bucket', $putString?->offsetGet('Bucket'));
        self::assertSame('backups/config.json', $putString?->offsetGet('Key'));

        $download = $this->tempDirectory . DIRECTORY_SEPARATOR . 'download.sql';
        $storage->getFile('backups/db.sql', $download);
        self::assertSame('downloaded-file', file_get_contents($download));
        self::assertSame('downloaded-string', $storage->readString('backups/config.json'));

        $storage->copy('s3://source-bucket/assets/image one.png', 'backups/copy.png');
        self::assertSame('source-bucket/assets/image%20one.png', $copy?->offsetGet('CopySource'));
        self::assertSame('AES256', $copy?->offsetGet('ServerSideEncryption'));
        self::assertTrue($storage->exists('backups/copy.png'));
        self::assertSame(17, $storage->size('backups/copy.png'));
        self::assertSame(hash('sha256', 'hash-me'), $storage->sha256('backups/copy.png'));
        self::assertCount(0, $mock);
    }

    public function testRejectsProviderThatIgnoresOrChangesRequestedServerSideEncryption(): void
    {
        foreach ([null, 'aws:kms'] as $actualEncryption) {
            $put = null;
            $mock = new MockHandler();
            $mock->append(static function (CommandInterface $command) use (&$put): Result {
                $put = $command;

                return new Result([]);
            });
            $head = [];
            if ($actualEncryption !== null) {
                $head['ServerSideEncryption'] = $actualEncryption;
            }
            $mock->append(new Result($head));

            try {
                $this->storage($mock)->putString('backups/tampered.json', '{}', 'application/json');
                self::fail('Expected missing or mismatched provider-side encryption to fail completion.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Backup object does not use the required server-side encryption.',
                    $exception->getMessage(),
                );
            }
            self::assertSame('AES256', $put?->offsetGet('ServerSideEncryption'));
            self::assertSame('HeadObject', $mock->getLastCommand()?->getName());
        }

        $kms = new MockHandler();
        $kms->append(new Result([]));
        $kms->append(new Result(['ServerSideEncryption' => 'aws:kms']));
        $this->storage($kms, 'aws:kms')->putString('backups/kms.json', '{}', 'application/json');
        self::assertSame('HeadObject', $kms->getLastCommand()?->getName());
    }

    public function testExistsReturnsFalseOnlyForNotFoundAndRethrowsOtherFailures(): void
    {
        $missing = new MockHandler();
        $missing->append(new AwsException('missing', new Command('HeadObject'), [
            'response' => new Response(404),
            'code' => 'NoSuchKey',
        ]));
        self::assertFalse($this->storage($missing)->exists('missing'));

        $failed = new MockHandler();
        $failed->append(new AwsException('denied', new Command('HeadObject'), [
            'response' => new Response(403),
            'code' => 'AccessDenied',
        ]));
        $this->expectException(AwsException::class);
        $this->storage($failed)->exists('denied');
    }

    public function testRejectsInvalidConfigurationKeysAndUnreadableFiles(): void
    {
        foreach (
            [
                [['endpoint' => '', 'bucket' => 'b', 'access_key_id' => 'a', 'secret_access_key' => 's'], 'endpoint'],
                [['endpoint' => 'https://s3.test', 'bucket' => '', 'access_key_id' => 'a', 'secret_access_key' => 's'], 'bucket'],
                [['endpoint' => 'https://s3.test', 'bucket' => 'b', 'access_key_id' => '', 'secret_access_key' => ''], 'credentials'],
                [['endpoint' => 'https://s3.test', 'bucket' => 'b', 'access_key_id' => 'a', 'secret_access_key' => 's', 'server_side_encryption' => 'none'], 'encryption'],
            ] as [$config, $expected]
        ) {
            try {
                new S3BackupObjectStorage($config);
                self::fail('Expected invalid S3 backup configuration.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($expected, strtolower($exception->getMessage()));
            }
        }

        $storage = $this->storage(new MockHandler());
        foreach (['', 'https://example.test/object', 's3://missing-key'] as $key) {
            try {
                $storage->readString($key);
                self::fail('Expected invalid backup object key.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        $storage->putFile('backup.sql', $this->tempDirectory . DIRECTORY_SEPARATOR . 'missing.sql', 'application/sql');
    }

    public function testGetFileFailsWhenProviderReturnsNoBodyOrFile(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $storage = $this->storage($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not create');
        $storage->getFile('missing.sql', $this->tempDirectory . DIRECTORY_SEPARATOR . 'missing.sql');
    }

    public function testGetFileReportsLocalWriteFailure(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['Body' => 'downloaded-file']));
        $storage = $this->storage($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write downloaded backup object');
        $storage->getFile('backup.sql', $this->tempDirectory);
    }

    public function testSha256RejectsNonStreamBodies(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['Body' => 'not-a-stream']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readable stream');
        $this->storage($mock)->sha256('backup.sql');
    }

    private function storage(MockHandler $mock, string $encryption = 'AES256'): S3BackupObjectStorage
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://s3.example.test',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
        ]);

        return new S3BackupObjectStorage([
            'endpoint' => 'https://s3.example.test',
            'region' => '',
            'bucket' => 'backup-bucket',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => true,
            'server_side_encryption' => $encryption,
        ], $client);
    }
}
