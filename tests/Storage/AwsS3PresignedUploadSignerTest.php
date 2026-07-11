<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Storage\AwsS3PresignedUploadSigner;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;

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
        $this->expectExceptionMessage('S3 server-side encryption must be AES256 or aws:kms.');
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
}
