<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Infrastructure\Storage\S3ObjectStorageInspector;

final class S3ObjectStorageInspectorTest extends TestCase
{
    public function testReadsPrivateObjectAndComputesAuthoritativeInspection(): void
    {
        $body = "%PDF-1.7\nprivate payment receipt";
        $mock = new MockHandler();
        $mock->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'Application/PDF; charset=binary',
        ]));
        $mock->append(new Result(['Body' => Utils::streamFor($body)]));

        $inspection = $this->inspector($mock)->inspect('withdrawals/42/7/token-receipt.pdf');

        self::assertNotNull($inspection);
        self::assertSame('withdrawals/42/7/token-receipt.pdf', $inspection->objectKey);
        self::assertSame('application/pdf', $inspection->contentType);
        self::assertSame(strlen($body), $inspection->byteSize);
        self::assertSame('sha256:' . hash('sha256', $body), $inspection->checksum);
        self::assertSame($body, $inspection->leadingBytes);
        self::assertSame('GetObject', $mock->getLastCommand()?->getName());
        self::assertSame('withdrawal-proofs', $mock->getLastCommand()?->offsetGet('Bucket'));
    }

    public function testReturnsNullWhenHeadOrGetReportsMissingObject(): void
    {
        $missingHead = new MockHandler();
        $missingHead->append($this->awsFailure('HeadObject', 404, 'NoSuchKey'));
        self::assertNull($this->inspector($missingHead)->inspect('withdrawals/42/7/missing.pdf'));

        $missingGet = new MockHandler();
        $missingGet->append(new Result(['ContentLength' => 5, 'ContentType' => 'application/pdf']));
        $missingGet->append($this->awsFailure('GetObject', 404, 'NotFound'));
        self::assertNull($this->inspector($missingGet)->inspect('withdrawals/42/7/disappeared.pdf'));
    }

    public function testWrapsProviderFailuresWithoutLeakingProviderDetails(): void
    {
        $headFailure = new MockHandler();
        $headFailure->append($this->awsFailure('HeadObject', 403, 'AccessDenied'));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($headFailure)->inspect('withdrawals/42/7/denied.pdf'),
            'Private object storage metadata inspection failed.',
        );

        $getFailure = new MockHandler();
        $getFailure->append(new Result(['ContentLength' => 5, 'ContentType' => 'application/pdf']));
        $getFailure->append($this->awsFailure('GetObject', 500, 'InternalError'));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($getFailure)->inspect('withdrawals/42/7/failed.pdf'),
            'Private object storage content inspection failed.',
        );
    }

    public function testWrapsUnexpectedHeadFailureAndDirectAwsGetFailure(): void
    {
        $headFailure = new class extends S3Client {
            public function __construct()
            {
            }

            /** @param array<string, mixed> $args */
            public function headObject(array $args): Result
            {
                throw new RuntimeException('connection closed before metadata response');
            }
        };
        $this->assertRuntimeMessage(
            fn () => $this->inspectorWithClient($headFailure)->inspect('withdrawals/42/7/head-transport.pdf'),
            'Private object storage metadata inspection failed.',
        );

        $providerFailure = $this->awsFailure('GetObject', 500, 'InternalError');
        $getFailure = new class($providerFailure) extends S3Client {
            public function __construct(private readonly AwsException $failure)
            {
            }

            /** @param array<string, mixed> $args */
            public function headObject(array $args): Result
            {
                return new Result(['ContentLength' => 5, 'ContentType' => 'application/pdf']);
            }

            /** @param array<string, mixed> $args */
            public function getObject(array $args): Result
            {
                throw $this->failure;
            }
        };
        $this->assertRuntimeMessage(
            fn () => $this->inspectorWithClient($getFailure)->inspect('withdrawals/42/7/get-provider.pdf'),
            'Private object storage content inspection failed.',
        );
    }

    public function testRejectsInvalidMetadataOversizedBodiesAndChangedContent(): void
    {
        $invalidLength = new MockHandler();
        $invalidLength->append(new Result(['ContentLength' => 'unknown']));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($invalidLength)->inspect('withdrawals/42/7/invalid.pdf'),
            'Private object storage returned an invalid content length.',
        );

        $oversized = new MockHandler();
        $oversized->append(new Result(['ContentLength' => 101]));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($oversized, 100)->inspect('withdrawals/42/7/large.pdf'),
            'Private object exceeds the configured inspection byte limit.',
        );

        $missingBody = new MockHandler();
        $missingBody->append(new Result(['ContentLength' => 5]));
        $missingBody->append(new Result([]));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($missingBody)->inspect('withdrawals/42/7/body.pdf'),
            'Private object storage returned no readable object body.',
        );

        $changed = new MockHandler();
        $changed->append(new Result(['ContentLength' => 5]));
        $changed->append(new Result(['Body' => 'four']));
        $this->assertRuntimeMessage(
            fn () => $this->inspector($changed)->inspect('withdrawals/42/7/changed.pdf'),
            'Private object storage content length changed during inspection.',
        );
    }

    public function testValidatesConfiguredServerSideEncryptionBeforeDownloading(): void
    {
        $body = "%PDF-1.7\nencrypted";
        $valid = new MockHandler();
        $valid->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'application/pdf',
            'ServerSideEncryption' => 'AES256',
        ]));
        $valid->append(new Result(['Body' => $body]));
        self::assertNotNull($this->inspector($valid, serverSideEncryption: 'AES256')->inspect('withdrawals/42/7/encrypted.pdf'));

        foreach ([null, 'aws:kms'] as $actualEncryption) {
            $mismatch = new MockHandler();
            $head = ['ContentLength' => strlen($body), 'ContentType' => 'application/pdf'];
            if ($actualEncryption !== null) {
                $head['ServerSideEncryption'] = $actualEncryption;
            }
            $mismatch->append(new Result($head));

            $this->assertRuntimeMessage(
                fn () => $this->inspector($mismatch, serverSideEncryption: 'AES256')->inspect('withdrawals/42/7/unencrypted.pdf'),
                'Private object does not use the required server-side encryption.',
            );
            self::assertSame('HeadObject', $mismatch->getLastCommand()?->getName());
        }
    }

    public function testValidatesConfigurationObjectKeysAndEmptyContentType(): void
    {
        foreach ([
            [['endpoint' => '', 'bucket' => 'b', 'access_key_id' => 'a', 'secret_access_key' => 's'], 'endpoint'],
            [['endpoint' => 'https://s3.test', 'bucket' => '', 'access_key_id' => 'a', 'secret_access_key' => 's'], 'bucket'],
            [['endpoint' => 'https://s3.test', 'bucket' => 'b', 'access_key_id' => '', 'secret_access_key' => ''], 'credentials'],
            [['endpoint' => 'https://s3.test', 'bucket' => 'b', 'access_key_id' => 'a', 'secret_access_key' => 's', 'max_inspect_bytes' => 0], 'byte limit'],
            [['endpoint' => 'https://s3.test', 'bucket' => 'b', 'access_key_id' => 'a', 'secret_access_key' => 's', 'server_side_encryption' => 'AES128'], 'server-side encryption'],
        ] as [$config, $message]) {
            try {
                new S3ObjectStorageInspector($config);
                self::fail('Expected invalid private S3 inspection configuration.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, strtolower($exception->getMessage()));
            }
        }

        $mock = new MockHandler();
        $mock->append(new Result(['ContentLength' => 0, 'ContentType' => '']));
        $mock->append(new Result(['Body' => '']));
        $inspection = $this->inspector($mock)->inspect('withdrawals/42/7/empty.pdf');
        self::assertSame('application/octet-stream', $inspection?->contentType);

        foreach (['', '/absolute.pdf', '../escape.pdf'] as $key) {
            try {
                $this->inspector(new MockHandler())->inspect($key);
                self::fail('Expected unsafe private object key failure.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function inspector(
        MockHandler $mock,
        int $maxBytes = 10_485_760,
        ?string $serverSideEncryption = null,
    ): S3ObjectStorageInspector
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://minio.example.test',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
        ]);

        return $this->inspectorWithClient($client, $maxBytes, $serverSideEncryption);
    }

    private function inspectorWithClient(
        S3Client $client,
        int $maxBytes = 10_485_760,
        ?string $serverSideEncryption = null,
    ): S3ObjectStorageInspector {

        $config = [
            'endpoint' => 'https://minio.example.test',
            'region' => '',
            'bucket' => 'withdrawal-proofs',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'max_inspect_bytes' => $maxBytes,
        ];
        if ($serverSideEncryption !== null) {
            $config['server_side_encryption'] = $serverSideEncryption;
        }

        return new S3ObjectStorageInspector($config, $client);
    }

    private function awsFailure(string $command, int $status, string $code): AwsException
    {
        return new AwsException($code, new Command($command), [
            'response' => new Response($status),
            'code' => $code,
        ]);
    }

    private function assertRuntimeMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected private object inspection failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
