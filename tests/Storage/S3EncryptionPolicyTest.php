<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Infrastructure\Storage\S3EncryptionPolicy;

final class S3EncryptionPolicyTest extends TestCase
{
    public function testAllowsUnconfiguredEncryptionForOptionalStorage(): void
    {
        $policy = S3EncryptionPolicy::fromConfig([], 'Asset S3');

        self::assertNull($policy->mode);
        self::assertFalse($policy->providerManaged);
        self::assertSame([], $policy->putParameters());
        self::assertSame('none', $policy->readinessEvidence());
        $policy->assertMetadata([], 'not used');
    }

    public function testUsesDefaultStandardEncryptionAndValidatesMetadata(): void
    {
        $policy = S3EncryptionPolicy::fromConfig(
            ['server_side_encryption' => ''],
            'Backup S3',
            'AES256',
        );

        self::assertSame('AES256', $policy->mode);
        self::assertFalse($policy->providerManaged);
        self::assertSame(['ServerSideEncryption' => 'AES256'], $policy->putParameters());
        self::assertSame('AES256', $policy->readinessEvidence());
        $policy->assertMetadata(['ServerSideEncryption' => 'AES256'], 'wrong encryption');
    }

    public function testRejectsMismatchedStandardEncryptionMetadata(): void
    {
        $policy = S3EncryptionPolicy::fromConfig(
            ['server_side_encryption' => 'aws:kms'],
            'Backup S3',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wrong encryption');
        $policy->assertMetadata(['ServerSideEncryption' => 'AES256'], 'wrong encryption');
    }

    public function testAcceptsCloudflareR2ManagedEncryptionWithoutSseHeaders(): void
    {
        $policy = S3EncryptionPolicy::fromConfig([
            'endpoint' => 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/',
            'server_side_encryption' => S3EncryptionPolicy::R2_AES256,
        ], 'Backup S3');

        self::assertSame(S3EncryptionPolicy::R2_AES256, $policy->mode);
        self::assertTrue($policy->providerManaged);
        self::assertSame([], $policy->putParameters());
        self::assertSame('cloudflare-r2-managed-aes256', $policy->readinessEvidence());
        $policy->assertMetadata([], 'provider managed');
    }

    public function testRejectsUnsupportedEncryptionMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Asset S3 server-side encryption must be AES256, aws:kms, or R2-AES256.'
        );

        S3EncryptionPolicy::fromConfig(['server_side_encryption' => 'none'], 'Asset S3');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidR2Endpoints(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://account.r2.cloudflarestorage.com'];
        yield 'wrong host' => ['https://s3.example.com'];
        yield 'credentials' => ['https://user:pass@account.r2.cloudflarestorage.com'];
        yield 'query' => ['https://account.r2.cloudflarestorage.com?mode=private'];
        yield 'fragment' => ['https://account.r2.cloudflarestorage.com#private'];
    }

    #[DataProvider('invalidR2Endpoints')]
    public function testRejectsR2ModeOutsideCredentialFreeCloudflareAccountEndpoint(
        string $endpoint,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Backup S3 R2-AES256 requires a credential-free HTTPS Cloudflare R2 account endpoint.'
        );

        S3EncryptionPolicy::fromConfig([
            'endpoint' => $endpoint,
            'server_side_encryption' => S3EncryptionPolicy::R2_AES256,
        ], 'Backup S3');
    }
}
