<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;

final class DeterministicPresignedUploadTest extends TestCase
{
    public function testBuildsConfigBackedDeterministicPresignedUpload(): void
    {
        $signer = new DeterministicPresignedUploadSigner([
            'endpoint' => 'https://r2.example.test',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => true,
        ]);

        $upload = $signer->presignPut(new PresignedUploadRequest(
            objectKey: 'organizations/99/assets/asset.png',
            contentType: 'image/png',
            byteSize: 4096,
            expiresAt: new DateTimeImmutable('2026-06-08 10:00:00 UTC'),
        ));

        self::assertStringStartsWith('https://r2.example.test/creative-assets/organizations/99/assets/asset.png?', $upload->url);
        self::assertSame('PUT', $upload->method);
        self::assertSame('organizations/99/assets/asset.png', $upload->objectKey);
        self::assertSame([
            'Content-Type' => 'image/png',
            'x-amz-meta-vertoad-byte-size' => '4096',
        ], $upload->headers);
        self::assertStringContainsString('X-VertoAD-Access-Key=%2A%2A%2A', $upload->url);
        self::assertStringContainsString('X-VertoAD-Expires=1780912800', $upload->url);
        self::assertStringContainsString('X-VertoAD-Signature=', $upload->url);
    }

    public function testRejectsMissingRequiredStorageConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage bucket is required.');

        new DeterministicPresignedUploadSigner([
            'endpoint' => 'https://r2.example.test',
            'bucket' => '',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
        ]);
    }

    public function testRejectsMissingEndpointAndCredentials(): void
    {
        try {
            new DeterministicPresignedUploadSigner([
                'endpoint' => '',
                'bucket' => 'creative-assets',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
            ]);
            self::fail('Expected missing endpoint failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Storage endpoint is required.', $exception->getMessage());
        }

        try {
            new DeterministicPresignedUploadSigner([
                'endpoint' => 'https://r2.example.test',
                'bucket' => 'creative-assets',
                'access_key_id' => '',
                'secret_access_key' => '',
            ]);
            self::fail('Expected missing credentials failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Storage access key and secret are required.', $exception->getMessage());
        }
    }

    public function testBuildsVirtualHostedStyleUrl(): void
    {
        $signer = new DeterministicPresignedUploadSigner([
            'endpoint' => 'https://r2.example.test:8443',
            'bucket' => 'creative-assets',
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => false,
        ]);

        $upload = $signer->presignPut(new PresignedUploadRequest(
            objectKey: 'organizations/99/assets/space name.webp',
            contentType: 'image/webp',
            byteSize: 512,
            expiresAt: new DateTimeImmutable('2026-06-08 10:00:00 UTC'),
        ));

        self::assertStringStartsWith(
            'https://creative-assets.r2.example.test:8443/organizations/99/assets/space%20name.webp?',
            $upload->url,
        );
    }
}
