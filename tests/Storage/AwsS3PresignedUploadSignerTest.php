<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use VertoAD\Infrastructure\Storage\AwsS3PresignedUploadSigner;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;

final class AwsS3PresignedUploadSignerTest extends TestCase
{
    public function testBuildsAwsSignatureV4PresignedPutForS3CompatibleStorage(): void
    {
        $signer = new AwsS3PresignedUploadSigner(
            [
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'region' => 'auto',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'path_style_endpoint' => true,
            ],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-08 09:45:00 UTC'),
        );

        $upload = $signer->presignPut(new PresignedUploadRequest(
            objectKey: 'organizations/99/assets/space name.png',
            contentType: 'image/png',
            byteSize: 4096,
            expiresAt: new DateTimeImmutable('2026-06-08 10:00:00 UTC'),
        ));

        self::assertSame('PUT', $upload->method);
        self::assertSame('organizations/99/assets/space name.png', $upload->objectKey);
        self::assertSame(200, $upload->statusCode);
        self::assertSame([
            'Content-Type' => 'image/png',
            'x-amz-meta-vertoad-byte-size' => '4096',
        ], $upload->headers);
        self::assertStringStartsWith(
            'https://account-id.r2.cloudflarestorage.com/creative-assets/organizations/99/assets/space%20name.png?',
            $upload->url,
        );
        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $upload->url);
        self::assertStringContainsString('X-Amz-Credential=access-key%2F', $upload->url);
        self::assertStringContainsString('X-Amz-Expires=900', $upload->url);
        self::assertStringContainsString('x-amz-meta-vertoad-byte-size=4096', $upload->url);
        self::assertStringContainsString('X-Amz-SignedHeaders=host%3Bx-amz-meta-vertoad-byte-size', $upload->url);
        self::assertStringContainsString('X-Amz-Signature=', $upload->url);
        self::assertStringNotContainsString('secret-key', $upload->url);
        self::assertStringNotContainsString('X-VertoAD-Signature', $upload->url);
    }

    public function testRejectsMissingRequiredS3Config(): void
    {
        try {
            new AwsS3PresignedUploadSigner([
                'endpoint' => '',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ]);
            self::fail('Expected missing endpoint failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 endpoint is required.', $exception->getMessage());
        }

        try {
            new AwsS3PresignedUploadSigner([
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => '',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ]);
            self::fail('Expected missing bucket failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 bucket is required.', $exception->getMessage());
        }

        try {
            new AwsS3PresignedUploadSigner([
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => 'creative-assets',
                'access_key_id' => '',
                'secret_access_key' => '',
            ]);
            self::fail('Expected missing credentials failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 access key and secret are required.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'S3 asset server-side encryption must be AES256, aws:kms, or R2-AES256.'
        );
        new AwsS3PresignedUploadSigner([
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'server_side_encryption' => 'none',
        ]);
    }

    public function testSignsRequiredServerSideEncryptionHeaderForPrivateUploads(): void
    {
        $signer = new AwsS3PresignedUploadSigner(
            [
                'endpoint' => 'https://minio.example.test',
                'region' => 'us-east-1',
                'bucket' => 'withdrawal-proofs',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'server_side_encryption' => 'AES256',
            ],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10 10:00:00 UTC'),
        );

        $upload = $signer->presignPut(new PresignedUploadRequest(
            objectKey: 'withdrawals/42/7/token-receipt.pdf',
            contentType: 'application/pdf',
            byteSize: 4096,
            expiresAt: new DateTimeImmutable('2026-07-10 10:15:00 UTC'),
        ));

        self::assertSame('AES256', $upload->headers['x-amz-server-side-encryption'] ?? null);
        self::assertStringContainsString('x-amz-server-side-encryption=AES256', $upload->url);
        self::assertStringContainsString(
            'X-Amz-SignedHeaders=host%3Bx-amz-meta-vertoad-byte-size%3Bx-amz-server-side-encryption',
            $upload->url,
        );
    }

    public function testRejectsExpiredUploadIntent(): void
    {
        $signer = new AwsS3PresignedUploadSigner(
            [
                'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-08 10:00:00 UTC'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('S3 presigned upload expiry must be in the future.');

        $signer->presignPut(new PresignedUploadRequest(
            objectKey: 'organizations/99/assets/asset.png',
            contentType: 'image/png',
            byteSize: 4096,
            expiresAt: new DateTimeImmutable('2026-06-08 09:59:59 UTC'),
        ));
    }

    public function testAssetStagingOnlyPresignRejectsFinalAndUnscopedKeys(): void
    {
        $signer = $this->signer(new MockHandler());
        $expiresAt = new DateTimeImmutable('2026-07-12 12:15:00 UTC');

        foreach ([
            'organizations/99/assets/final/source-token/final-token-value.png',
            'organizations/99/assets/source-token.png',
        ] as $objectKey) {
            try {
                $signer->presignPut(new PresignedUploadRequest(
                    objectKey: $objectKey,
                    contentType: 'image/png',
                    byteSize: 16,
                    expiresAt: $expiresAt,
                    stagingOnly: true,
                ));
                self::fail('Expected staging-only presign to reject ' . $objectKey);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'S3 asset presigned uploads must target a staging object key.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testWritesValidatedBytesToContentBoundFinalKeyWithoutConditionalPut(): void
    {
        $body = "\x89PNG\r\n\x1A\nimmutable bytes";
        $put = null;
        $delete = null;
        $mock = new MockHandler();
        $mock->append(static function (CommandInterface $command) use (&$put): Result {
            $put = $command;

            return new Result([]);
        });
        $mock->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'Image/PNG; charset=binary',
            'ServerSideEncryption' => 'AES256',
        ]));
        $mock->append(new Result(['Body' => Utils::streamFor($body)]));
        $mock->append(static function (CommandInterface $command) use (&$delete): Result {
            $delete = $command;

            return new Result([]);
        });
        $signer = $this->signer($mock, 'AES256');
        $staging = $this->inspection('organizations/99/assets/staging/space-name.png', $body);
        $finalKey = $this->finalKey($staging->objectKey, $body);

        $final = $signer->writeFinalFromValidatedBytes($staging, $finalKey);
        $signer->delete($staging->objectKey);

        self::assertSame('PutObject', $put?->getName());
        self::assertSame('creative-assets', $put?->offsetGet('Bucket'));
        self::assertSame($finalKey, $put?->offsetGet('Key'));
        self::assertSame($body, $put?->offsetGet('Body'));
        self::assertSame('image/png', $put?->offsetGet('ContentType'));
        self::assertSame('public, max-age=31536000, immutable', $put?->offsetGet('CacheControl'));
        self::assertSame('inline', $put?->offsetGet('ContentDisposition'));
        self::assertSame(['sha256' => hash('sha256', $body)], $put?->offsetGet('Metadata'));
        self::assertFalse($put?->hasParam('IfNoneMatch') ?? false);
        self::assertSame('AES256', $put?->offsetGet('ServerSideEncryption'));
        self::assertSame($finalKey, $final->objectKey);
        self::assertSame('image/png', $final->contentType);
        self::assertSame(strlen($body), $final->byteSize);
        self::assertSame($staging->width, $final->width);
        self::assertSame($staging->height, $final->height);
        self::assertSame($body, $final->body);
        self::assertSame('sha256:' . hash('sha256', $body), $final->checksum);
        self::assertSame('DeleteObject', $delete?->getName());
        self::assertSame($staging->objectKey, $delete?->offsetGet('Key'));
        self::assertCount(0, $mock);
    }

    public function testPromotionRejectsFinalKeyThatIsNotBoundToValidatedBytes(): void
    {
        $body = "\x89PNG\r\n\x1A\nimmutable bytes";
        $mock = new MockHandler();

        try {
            $this->signer($mock)->writeFinalFromValidatedBytes(
                $this->inspection('organizations/99/assets/staging/source.png', $body),
                'organizations/99/assets/final/source/final-token-value-sha256-' . str_repeat('0', 64) . '.png',
            );
            self::fail('Expected an unbound final key to reject promotion.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 asset final key checksum does not match validated bytes.', $exception->getMessage());
        }

        self::assertCount(0, $mock);
    }

    public function testPromotionRejectsSameKeyAndWrapsCopyAndDeleteProviderFailures(): void
    {
        $body = "\x89PNG\r\n\x1A\nbytes";
        $staging = $this->inspection('organizations/99/assets/staging/source.png', $body);
        $unused = new MockHandler();
        try {
            $this->signer($unused)->writeFinalFromValidatedBytes($staging, $staging->objectKey);
            self::fail('Expected same-key promotion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 asset final key must differ from its staging key.', $exception->getMessage());
        }

        foreach ([
            [
                'organizations/99/assets/not-staging/source.png',
                $this->finalKey('organizations/99/assets/staging/source.png', $body),
                'S3 asset staging key is invalid.',
            ],
            [
                $staging->objectKey,
                $this->finalKey('organizations/99/assets/staging/other-source.png', $body),
                'S3 asset final key must be a server-generated content-bound final key for its staging object.',
            ],
        ] as [$sourceKey, $finalKey, $message]) {
            try {
                $this->signer(new MockHandler())->writeFinalFromValidatedBytes(
                    new StoredObjectInspection(
                        objectKey: $sourceKey,
                        contentType: $staging->contentType,
                        byteSize: $staging->byteSize,
                        width: $staging->width,
                        height: $staging->height,
                        durationSeconds: null,
                        checksum: $staging->checksum,
                        leadingBytes: $staging->leadingBytes,
                        body: $body,
                    ),
                    $finalKey,
                );
                self::fail('Expected final key namespace validation to fail.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $copyFailure = new MockHandler();
        $copyFailure->append($this->awsFailure('PutObject', 403, 'AccessDenied'));
        $this->assertRuntimeMessage(
            fn () => $this->signer($copyFailure)->writeFinalFromValidatedBytes(
                $staging,
                $this->finalKey($staging->objectKey, $body),
            ),
            'S3 asset object promotion failed.',
        );

        $deleteFailure = new MockHandler();
        $deleteFailure->append($this->awsFailure('DeleteObject', 500, 'InternalError'));
        $this->assertRuntimeMessage(
            fn () => $this->signer($deleteFailure)->delete($staging->objectKey),
            'S3 asset object deletion failed.',
        );
    }

    public function testPromotionRejectsInvalidProviderReadback(): void
    {
        $body = "\x89PNG\r\n\x1A\nbytes";
        $staging = $this->inspection('organizations/99/assets/staging/source.png', $body);
        $throwingStream = $this->createStub(StreamInterface::class);
        $throwingStream->method('eof')->willReturn(false);
        $throwingStream->method('read')->willThrowException(new RuntimeException('stream failed'));
        $cases = [
            'invalid length' => [
                ['ContentLength' => 'invalid', 'ContentType' => 'image/png'],
                ['Body' => $body],
                null,
                'S3 asset object returned an invalid content length.',
            ],
            'missing content type' => [
                ['ContentLength' => strlen($body), 'ContentType' => ''],
                ['Body' => $body],
                null,
                'S3 asset object returned no content type.',
            ],
            'wrong encryption' => [
                ['ContentLength' => strlen($body), 'ContentType' => 'image/png'],
                ['Body' => $body],
                'AES256',
                'S3 asset object does not use the required server-side encryption.',
            ],
            'stream failure' => [
                ['ContentLength' => strlen($body), 'ContentType' => 'image/png'],
                ['Body' => $throwingStream],
                null,
                'S3 asset object content inspection failed.',
            ],
            'missing body' => [
                ['ContentLength' => strlen($body), 'ContentType' => 'image/png'],
                [],
                null,
                'S3 asset object returned no readable body.',
            ],
            'changed length' => [
                ['ContentLength' => strlen($body) + 1, 'ContentType' => 'image/png'],
                ['Body' => $body],
                null,
                'S3 asset object length changed during inspection.',
            ],
        ];

        foreach ($cases as [$head, $object, $encryption, $message]) {
            $mock = new MockHandler();
            $mock->append(new Result([]));
            $mock->append(new Result($head));
            $mock->append(new Result($object));

            $this->assertRuntimeMessage(
                fn () => $this->signer($mock, $encryption)->writeFinalFromValidatedBytes(
                    $staging,
                    $this->finalKey($staging->objectKey, $body),
                ),
                $message,
            );
        }
    }

    public function testFinalizationConfigurationAndSourceBytesFailClosed(): void
    {
        foreach ([
            ['max_read_bytes' => 0],
            ['asset_cache_control' => ''],
        ] as $override) {
            try {
                $this->signer(new MockHandler(), configOverride: $override);
                self::fail('Expected invalid asset finalization configuration to fail.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'S3 asset finalization limits and cache control must be configured.',
                    $exception->getMessage(),
                );
            }
        }

        $body = "\x89PNG\r\n\x1A\nbytes";
        $staging = $this->inspection('organizations/99/assets/staging/source.png', $body);
        $missingBody = new StoredObjectInspection(
            objectKey: $staging->objectKey,
            contentType: $staging->contentType,
            byteSize: $staging->byteSize,
            width: $staging->width,
            height: $staging->height,
            durationSeconds: null,
            checksum: $staging->checksum,
            leadingBytes: $staging->leadingBytes,
            body: null,
        );
        try {
            $this->signer(new MockHandler())->writeFinalFromValidatedBytes(
                $missingBody,
                $this->finalKey($staging->objectKey, $body),
            );
            self::fail('Expected missing authoritative bytes to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 asset finalization requires authoritative object bytes.', $exception->getMessage());
        }

        $mismatch = new StoredObjectInspection(
            objectKey: $staging->objectKey,
            contentType: $staging->contentType,
            byteSize: $staging->byteSize + 1,
            width: $staging->width,
            height: $staging->height,
            durationSeconds: null,
            checksum: $staging->checksum,
            leadingBytes: $staging->leadingBytes,
            body: $body,
        );
        try {
            $this->signer(new MockHandler())->writeFinalFromValidatedBytes(
                $mismatch,
                $this->finalKey($staging->objectKey, $body),
            );
            self::fail('Expected inconsistent staging metadata to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('S3 asset staging metadata does not match its bytes.', $exception->getMessage());
        }
    }

    public function testPrivateInspectionHandlesNotFoundLimitsAndProviderFailures(): void
    {
        foreach ([
            $this->awsFailure('HeadObject', 404, 'NoSuchKey'),
            $this->awsFailure('HeadObject', 400, 'NotFound'),
        ] as $notFound) {
            $mock = new MockHandler();
            $mock->append($notFound);
            self::assertNull($this->signer($mock)->inspect('organizations/99/assets/final/missing/object.png'));
        }

        foreach ([
            $this->awsFailure('HeadObject', 500, 'InternalError'),
            new RuntimeException('Synthetic handler failure.'),
        ] as $failure) {
            $mock = new MockHandler();
            $mock->append($failure);
            $this->assertRuntimeMessage(
                fn () => $this->signer($mock)->inspect('organizations/99/assets/final/source/object.png'),
                'S3 asset object inspection failed.',
            );
        }

        $body = "\x89PNG\r\n\x1A\ninspect";
        $stringLength = new MockHandler();
        $stringLength->append(new Result([
            'ContentLength' => (string) strlen($body),
            'ContentType' => 'image/png',
        ]));
        $stringLength->append(new Result(['Body' => $body]));
        $inspection = $this->signer($stringLength)->inspect(
            'organizations/99/assets/final/source/object.png',
        );
        self::assertNull($inspection?->body);
        self::assertSame(substr($body, 0, 512), $inspection?->leadingBytes);
        self::assertSame('sha256:' . hash('sha256', $body), $inspection?->checksum);

        $oversized = new MockHandler();
        $oversized->append(new Result(['ContentLength' => 2, 'ContentType' => 'image/png']));
        $this->assertRuntimeMessage(
            fn () => $this->signer($oversized, configOverride: ['max_read_bytes' => 1])
                ->inspect('organizations/99/assets/final/source/object.png'),
            'S3 asset object exceeds the configured read limit.',
        );

        $missingDuringGet = new MockHandler();
        $missingDuringGet->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'image/png',
        ]));
        $missingDuringGet->append($this->awsFailure('GetObject', 404, 'NoSuchKey'));
        self::assertNull($this->signer($missingDuringGet)->inspect(
            'organizations/99/assets/final/source/object.png',
        ));

        $getFailure = new MockHandler();
        $getFailure->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'image/png',
        ]));
        $getFailure->append($this->awsFailure('GetObject', 500, 'InternalError'));
        $this->assertRuntimeMessage(
            fn () => $this->signer($getFailure)->inspect('organizations/99/assets/final/source/object.png'),
            'S3 asset object content inspection failed.',
        );

        $unexpectedGetFailure = new MockHandler();
        $unexpectedGetFailure->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'image/png',
        ]));
        $unexpectedGetFailure->append(new RuntimeException('Synthetic unexpected get failure.'));
        $this->assertRuntimeMessage(
            fn () => $this->signer($unexpectedGetFailure)->inspect('organizations/99/assets/final/source/object.png'),
            'S3 asset object content inspection failed.',
        );
    }

    public function testStreamingInspectionRejectsEmptyAndOverLimitStreams(): void
    {
        $body = "\x89PNG\r\n\x1A\nstream";
        $emptyStream = $this->createStub(StreamInterface::class);
        $emptyStream->method('eof')->willReturn(false);
        $emptyStream->method('read')->willReturn('');

        $empty = new MockHandler();
        $empty->append(new Result([]));
        $empty->append(new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'image/png',
        ]));
        $empty->append(new Result(['Body' => $emptyStream]));
        $this->assertRuntimeMessage(
            fn () => $this->signer($empty)->writeFinalFromValidatedBytes(
                $this->inspection('organizations/99/assets/staging/source.png', $body),
                $this->finalKey('organizations/99/assets/staging/source.png', $body),
            ),
            'S3 asset object length changed during inspection.',
        );

        $overLimitBody = 'ab';
        $overLimitStream = $this->createStub(StreamInterface::class);
        $overLimitStream->method('eof')->willReturn(false);
        $overLimitStream->method('read')->willReturn($overLimitBody);
        $overLimit = new MockHandler();
        $overLimit->append(new Result([]));
        $overLimit->append(new Result([
            'ContentLength' => 1,
            'ContentType' => 'image/png',
        ]));
        $overLimit->append(new Result(['Body' => $overLimitStream]));
        $this->assertRuntimeMessage(
            fn () => $this->signer($overLimit, configOverride: ['max_read_bytes' => 1])
                ->writeFinalFromValidatedBytes(
                    $this->inspection('organizations/99/assets/staging/source.png', $body),
                    $this->finalKey('organizations/99/assets/staging/source.png', $body),
                ),
            'S3 asset object content inspection failed.',
        );
    }

    public function testPromotionRejectsProviderReadbackWithDifferentBytes(): void
    {
        $body = "\x89PNG\r\n\x1A\noriginal";
        $different = "\x89PNG\r\n\x1A\nchanged!";
        self::assertSame(strlen($body), strlen($different));
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $mock->append(new Result([
            'ContentLength' => strlen($different),
            'ContentType' => 'image/png',
        ]));
        $mock->append(new Result(['Body' => $different]));

        $this->assertRuntimeMessage(
            fn () => $this->signer($mock)->writeFinalFromValidatedBytes(
                $this->inspection('organizations/99/assets/staging/source.png', $body),
                $this->finalKey('organizations/99/assets/staging/source.png', $body),
            ),
            'S3 promoted asset verification failed.',
        );
    }

    /** @param array<string, mixed> $configOverride */
    private function signer(
        MockHandler $mock,
        ?string $serverSideEncryption = null,
        array $configOverride = [],
    ): AwsS3PresignedUploadSigner
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $mock,
            'retries' => 0,
        ]);
        $config = [
            'endpoint' => 'https://account-id.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => true,
        ];
        if ($serverSideEncryption !== null) {
            $config['server_side_encryption'] = $serverSideEncryption;
        }
        $config = array_replace($config, $configOverride);

        return new AwsS3PresignedUploadSigner(
            $config,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-12 12:00:00 UTC'),
            $client,
        );
    }

    private function inspection(string $objectKey, string $body): StoredObjectInspection
    {
        return new StoredObjectInspection(
            objectKey: $objectKey,
            contentType: 'image/png',
            byteSize: strlen($body),
            width: 17,
            height: 11,
            durationSeconds: null,
            checksum: 'sha256:' . hash('sha256', $body),
            leadingBytes: substr($body, 0, 512),
            body: $body,
        );
    }

    private function finalKey(string $stagingObjectKey, string $body, string $token = 'final-token-value'): string
    {
        self::assertMatchesRegularExpression(
            '~^organizations/([1-9][0-9]*)/assets/staging/([A-Za-z0-9_-]+)\\.([a-z0-9]+)$~D',
            $stagingObjectKey,
        );
        preg_match(
            '~^organizations/([1-9][0-9]*)/assets/staging/([A-Za-z0-9_-]+)\\.([a-z0-9]+)$~D',
            $stagingObjectKey,
            $matches,
        );

        return sprintf(
            'organizations/%d/assets/final/%s/%s-sha256-%s.%s',
            (int) $matches[1],
            $matches[2],
            $token,
            hash('sha256', $body),
            $matches[3],
        );
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
            self::fail('Expected S3 asset storage operation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
