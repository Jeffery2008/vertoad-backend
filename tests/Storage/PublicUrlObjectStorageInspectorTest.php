<?php

declare(strict_types=1);

namespace VertoAD\Tests\Storage;

use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Infrastructure\Storage\PublicUrlObjectStorageInspector;
use VertoAD\Infrastructure\Storage\StoredObjectFetchResult;
use VertoAD\Infrastructure\Storage\UnavailableObjectStorageInspector;

final class PublicUrlObjectStorageInspectorTest extends TestCase
{
    public function testLocalAppFactoryUsesPublicUrlInspectorWhenConfigured(): void
    {
        $factory = new \ReflectionMethod(AppFactory::class, 'objectStorageInspector');

        $inspector = $factory->invoke(null, [
            'app' => ['env' => 'testing'],
            'storage' => ['s3' => ['public_base_url' => 'https://assets.example.test']],
        ]);

        self::assertInstanceOf(PublicUrlObjectStorageInspector::class, $inspector);
    }

    public function testInspectsPublicObjectUrlWithEncodedKeyAndImageDimensions(): void
    {
        $seenUrl = null;
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAYAAACi7K5AAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);
        $inspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test/root'],
            function (string $url) use (&$seenUrl, $png): StoredObjectFetchResult {
                $seenUrl = $url;

                return new StoredObjectFetchResult(200, [
                    'Content-Type' => 'image/png; charset=binary',
                    'Content-Length' => (string) strlen($png),
                ], $png);
            },
        );

        $object = $inspector->inspect('organizations/99/assets/space name.png');

        self::assertSame('https://assets.example.test/root/organizations/99/assets/space%20name.png', $seenUrl);
        self::assertNotNull($object);
        self::assertSame('organizations/99/assets/space name.png', $object->objectKey);
        self::assertSame('image/png', $object->contentType);
        self::assertSame(strlen($png), $object->byteSize);
        self::assertSame(2, $object->width);
        self::assertSame(3, $object->height);
        self::assertSame('sha256:' . hash('sha256', $png), $object->checksum);
        self::assertSame(substr($png, 0, 512), $object->leadingBytes);
    }

    public function testReturnsNullForMissingObjectAndRejectsUpstreamErrors(): void
    {
        $missing = new PublicUrlObjectStorageInspector(['public_base_url' => 'https://assets.example.test'], static fn (): null => null);
        self::assertNull($missing->inspect('missing.png'));

        $notFound = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(404, [], ''),
        );
        self::assertNull($notFound->inspect('missing.png'));

        $failing = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(503, [], 'unavailable'),
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Object storage returned HTTP 503 while inspecting uploaded object.');

        $failing->inspect('unavailable.png');
    }

    public function testRejectsInjectedObjectThatExceedsTheInspectionLimit(): void
    {
        $inspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test', 'max_inspect_bytes' => 16],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(
                200,
                ['content-type' => 'text/plain', 'content-length' => '17'],
                str_repeat('x', 17),
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Object storage object exceeds the configured inspection byte limit.');
        $inspector->inspect('oversized.txt');
    }

    public function testRejectsInjectedObjectWhoseDeclaredLengthDoesNotMatchItsBody(): void
    {
        $inspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test', 'max_inspect_bytes' => 16],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(
                200,
                ['content-type' => 'text/plain', 'content-length' => '1'],
                'two',
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Object storage object length changed during inspection.');
        $inspector->inspect('changed.txt');
    }

    public function testAcceptsOnlyCompletePartialResponses(): void
    {
        $complete = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test', 'max_inspect_bytes' => 16],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(
                206,
                [
                    'content-type' => 'text/plain',
                    'content-length' => '5',
                    'content-range' => 'bytes 0-4/5',
                ],
                'hello',
            ),
        );
        self::assertSame(5, $complete->inspect('complete.txt')?->byteSize);

        $truncated = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test', 'max_inspect_bytes' => 16],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(
                206,
                [
                    'content-type' => 'text/plain',
                    'content-length' => '16',
                    'content-range' => 'bytes 0-15/17',
                ],
                str_repeat('x', 16),
            ),
        );

        try {
            $truncated->inspect('truncated.txt');
            self::fail('Expected a truncated partial response to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Object storage object length changed during inspection.', $exception->getMessage());
        }

        $missingRange = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test', 'max_inspect_bytes' => 16],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(
                206,
                ['content-type' => 'text/plain', 'content-length' => '5'],
                'hello',
            ),
        );
        try {
            $missingRange->inspect('missing-range.txt');
            self::fail('Expected a partial response without Content-Range to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Object storage object length changed during inspection.', $exception->getMessage());
        }
    }

    public function testExtractsJsonDimensionsAndDefaultsTextGeometry(): void
    {
        $json = '{"canvas":{"width":600,"height":500},"objects":[]}';
        $jsonInspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                'content-type' => 'application/json',
            ], $json),
        );
        $jsonObject = $jsonInspector->inspect('creative.json');

        self::assertNotNull($jsonObject);
        self::assertSame(600, $jsonObject->width);
        self::assertSame(500, $jsonObject->height);

        $textInspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                'content-type' => 'text/plain',
            ], 'plain copy'),
        );
        $textObject = $textInspector->inspect('creative.txt');

        self::assertNotNull($textObject);
        self::assertSame(1, $textObject->width);
        self::assertSame(1, $textObject->height);
    }

    public function testJsonGeometryFallsBackForMalformedAndUnsupportedShapes(): void
    {
        foreach (
            [
                '{"canvas":',
                '"not an object"',
                '{"canvas":"not an object"}',
                '{"canvas":{"width":"wide","height":500}}',
            ] as $body
        ) {
            $inspector = new PublicUrlObjectStorageInspector(
                ['public_base_url' => 'https://assets.example.test'],
                static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                    'content-type' => 'application/json',
                ], $body),
            );

            $object = $inspector->inspect('creative.json');

            self::assertNotNull($object);
            self::assertSame(1, $object->width);
            self::assertSame(1, $object->height);
            self::assertNull($object->durationSeconds);
        }
    }

    public function testDefaultFetcherReadsFileUrlsAndTreatsMissingFilesAsMissing(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-public-inspector-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'creative.txt';
        file_put_contents($path, 'plain copy');

        try {
            $baseUrl = 'file:///' . str_replace('\\', '/', $directory);
            $inspector = new PublicUrlObjectStorageInspector(['public_base_url' => $baseUrl, 'max_inspect_bytes' => 16]);

            $object = $inspector->inspect('creative.txt');

            self::assertNotNull($object);
            self::assertSame('application/octet-stream', $object->contentType);
            self::assertSame(strlen('plain copy'), $object->byteSize);
            self::assertSame(1, $object->width);
            self::assertSame(1, $object->height);
            self::assertSame('sha256:' . hash('sha256', 'plain copy'), $object->checksum);
            self::assertNull($inspector->inspect('missing.txt'));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testDefaultFetcherBoundsFileReadsWhenTheSourceExceedsTheLimit(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-public-inspector-limit-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'oversized.txt';
        file_put_contents($path, str_repeat('x', 17));

        try {
            $baseUrl = 'file:///' . str_replace('\\', '/', $directory);
            $inspector = new PublicUrlObjectStorageInspector(['public_base_url' => $baseUrl, 'max_inspect_bytes' => 16]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Object storage object exceeds the configured inspection byte limit.');
            $inspector->inspect('oversized.txt');
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testParsesRawHttpHeadersForDefaultFetcher(): void
    {
        $inspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): null => null,
        );

        self::assertSame(206, $this->invokePrivate($inspector, 'httpStatus', [
            ['HTTP/1.1 206 Partial Content'],
        ]));

        $headers = $this->invokePrivate($inspector, 'headers', [
            [
                'HTTP/1.1 206 Partial Content',
                'Content-Type: text/plain; charset=utf-8',
                'X-Trace: one:two',
            ],
        ]);

        self::assertSame([
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Trace' => 'one:two',
        ], $headers);
    }

    public function testUsesInjectedAndDefaultVideoProbes(): void
    {
        $video = "\x00\x00\x00\x18ftypmp42";
        $inspector = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                'content-type' => 'video/mp4',
            ], $video),
            static fn (string $body, string $objectKey, string $contentType): array => [
                'width' => strlen($body) === 12 && $objectKey === 'creative.mp4' && $contentType === 'video/mp4' ? 640 : 0,
                'height' => 360,
                'duration_seconds' => 30.5,
            ],
        );
        $object = $inspector->inspect('creative.mp4');

        self::assertNotNull($object);
        self::assertSame(640, $object->width);
        self::assertSame(360, $object->height);
        self::assertSame(30.5, $object->durationSeconds);

        $defaultProbe = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                'content-type' => 'video/mp4',
            ], $video),
        );
        $defaultObject = $defaultProbe->inspect('minimal.mp4');

        self::assertNotNull($defaultObject);
        self::assertSame(0, $defaultObject->width);
        self::assertSame(0, $defaultObject->height);
        self::assertNull($defaultObject->durationSeconds);

        foreach ([['video/webm', 'minimal.webm'], ['video/avi', 'minimal.avi']] as [$contentType, $objectKey]) {
            $variant = new PublicUrlObjectStorageInspector(
                ['public_base_url' => 'https://assets.example.test'],
                static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                    'content-type' => $contentType,
                ], "\x1A\x45\xDF\xA3"),
            );
            $variantObject = $variant->inspect($objectKey);

            self::assertNotNull($variantObject);
            self::assertSame(0, $variantObject->width);
            self::assertSame(0, $variantObject->height);
            self::assertNull($variantObject->durationSeconds);
        }

        $tempUnavailable = new PublicUrlObjectStorageInspector(
            ['public_base_url' => 'https://assets.example.test'],
            static fn (): StoredObjectFetchResult => new StoredObjectFetchResult(200, [
                'content-type' => 'video/mp4',
            ], $video),
            null,
            static fn (): null => null,
        );
        $unavailableObject = $tempUnavailable->inspect('temp-unavailable.mp4');

        self::assertNotNull($unavailableObject);
        self::assertSame(0, $unavailableObject->width);
        self::assertSame(0, $unavailableObject->height);
        self::assertNull($unavailableObject->durationSeconds);
    }

    public function testRejectsMissingPublicBaseUrlAndUnavailableInspectorAlwaysMisses(): void
    {
        try {
            new PublicUrlObjectStorageInspector(['public_base_url' => '']);
            self::fail('Expected missing public base URL to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Object storage public_base_url is required for upload inspection.', $exception->getMessage());
        }

        $unavailable = new UnavailableObjectStorageInspector();
        self::assertNull($unavailable->inspect('anything.png'));
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivate(PublicUrlObjectStorageInspector $inspector, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($inspector, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($inspector, ...$arguments);
    }
}
