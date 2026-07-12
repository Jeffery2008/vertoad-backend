<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Assets\AssetObjectKey;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetSnapshotLease;
use VertoAD\Infrastructure\Storage\PresignedUploadRequest;

final class AssetDomainTest extends TestCase
{
    public function testObjectKeyEncodesEveryPathSegment(): void
    {
        $key = new AssetObjectKey('organizations/99/assets/space name+#.png');

        self::assertSame('organizations/99/assets/space%20name%2B%23.png', $key->encodedPath());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeObjectKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'surrounding whitespace' => [' key.png'];
        yield 'too long' => [str_repeat('a', 513)];
        yield 'absolute' => ['/key.png'];
        yield 'backslash' => ['assets\\key.png'];
        yield 'control' => ["assets/key\0.png"];
        yield 'empty segment' => ['assets//key.png'];
        yield 'dot segment' => ['assets/./key.png'];
        yield 'parent segment' => ['assets/../key.png'];
    }

    #[DataProvider('unsafeObjectKeys')]
    public function testObjectKeyRejectsUnsafePaths(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetObjectKey($value);
    }

    public function testPresignedUploadRequestAcceptsNormalizedMetadata(): void
    {
        $request = new PresignedUploadRequest(
            'organizations/99/assets/source.png',
            'image/png',
            15,
            new DateTimeImmutable('+5 minutes'),
        );

        self::assertSame(15, $request->byteSize);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidUploadMetadata(): iterable
    {
        yield 'uppercase MIME' => ['Image/PNG', 1];
        yield 'whitespace MIME' => [' image/png', 1];
        yield 'invalid MIME' => ['image', 1];
        yield 'zero bytes' => ['image/png', 0];
        yield 'negative bytes' => ['image/png', -1];
    }

    #[DataProvider('invalidUploadMetadata')]
    public function testPresignedUploadRequestRejectsInvalidMetadata(string $contentType, int $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PresignedUploadRequest('assets/source.png', $contentType, $bytes, new DateTimeImmutable('+5 minutes'));
    }

    public function testSnapshotArtifactsAndLeaseAcceptValidIdentity(): void
    {
        $png = AssetTestFixtures::image();
        $webp = AssetTestFixtures::image('image/webp');
        $artifacts = new AssetSnapshotArtifacts(
            'assets/derived/snapshot.png',
            'assets/derived/snapshot.webp',
            'assets/derived/thumbnail.webp',
            $png,
            $webp,
            $webp,
            4,
            3,
            4,
            3,
        );
        $lease = new AssetSnapshotLease(9, AssetTestFixtures::asset(), 1, 'valid-lease-token');

        self::assertSame('assets/derived/snapshot.png', $artifacts->pngObjectKey);
        self::assertSame(9, $lease->jobId);
    }

    public function testSnapshotArtifactsRejectInvalidKeysImagesAndDimensions(): void
    {
        $png = AssetTestFixtures::image();
        $webp = AssetTestFixtures::image('image/webp');
        $base = [
            'pngObjectKey' => 'assets/derived/snapshot.png',
            'webpObjectKey' => 'assets/derived/snapshot.webp',
            'thumbnailWebpObjectKey' => 'assets/derived/thumbnail.webp',
            'pngBytes' => $png,
            'webpBytes' => $webp,
            'thumbnailWebpBytes' => $webp,
            'width' => 4,
            'height' => 3,
            'thumbnailWidth' => 4,
            'thumbnailHeight' => 3,
        ];
        foreach ([
            ['webpObjectKey' => $base['pngObjectKey']],
            ['pngBytes' => ''],
            ['pngBytes' => $webp],
            ['webpBytes' => $png],
            ['thumbnailWebpBytes' => $png],
            ['width' => 0],
            ['height' => 0],
            ['thumbnailWidth' => 0],
            ['thumbnailHeight' => 0],
            ['thumbnailWidth' => 5],
            ['thumbnailHeight' => 4],
        ] as $override) {
            try {
                new AssetSnapshotArtifacts(...array_replace($base, $override));
                self::fail('Expected invalid snapshot artifacts.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSnapshotLeaseRejectsInvalidIdentity(): void
    {
        $asset = AssetTestFixtures::asset();
        $transient = AssetTestFixtures::asset(id: 0);
        foreach ([
            [0, $asset, 1, 'valid-lease-token'],
            [1, $transient, 1, 'valid-lease-token'],
            [1, $asset, 0, 'valid-lease-token'],
            [1, $asset, 1, 'short'],
            [1, $asset, 1, str_repeat('a', 65)],
            [1, $asset, 1, 'invalid lease token'],
        ] as [$jobId, $candidate, $attempts, $token]) {
            try {
                new AssetSnapshotLease($jobId, $candidate, $attempts, $token);
                self::fail('Expected invalid snapshot lease.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
