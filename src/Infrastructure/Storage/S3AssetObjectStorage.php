<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use VertoAD\Domain\Assets\AssetObjectKey;
use VertoAD\Service\Assets\AssetObjectStorageInterface;

final readonly class S3AssetObjectStorage implements AssetObjectStorageInterface, ObjectStorageInspectorInterface
{
    private S3Client $client;
    private string $bucket;
    private int $maxReadBytes;
    private string $cacheControl;
    private S3EncryptionPolicy $encryption;

    /** @var \Closure(string, string, string): array{width:int, height:int, duration_seconds:?float} */
    private \Closure $videoProbe;

    /** @var \Closure(): ?string */
    private \Closure $temporaryPathFactory;

    /**
     * @param array<string, mixed> $config
     * @param (callable(string, string, string): array{width:int, height:int, duration_seconds:?float})|null $videoProbe
     * @param (callable(): ?string)|null $temporaryPathFactory
     */
    public function __construct(
        array $config,
        ?S3Client $client = null,
        ?callable $videoProbe = null,
        ?callable $temporaryPathFactory = null,
    )
    {
        $endpoint = rtrim(trim((string) ($config['endpoint'] ?? '')), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $this->maxReadBytes = (int) ($config['max_read_bytes'] ?? 209_715_200);
        $this->cacheControl = trim((string) ($config['snapshot_cache_control'] ?? 'public, max-age=31536000, immutable'));
        $this->encryption = S3EncryptionPolicy::fromConfig($config, 'Asset S3');
        $this->videoProbe = \Closure::fromCallable($videoProbe ?? $this->defaultVideoProbe(...));
        $this->temporaryPathFactory = \Closure::fromCallable(
            $temporaryPathFactory ?? static fn (): ?string => tempnam(sys_get_temp_dir(), 'vertoad-asset-') ?: null,
        );

        if ($endpoint === '' || $this->bucket === '' || $accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('Configured S3 endpoint, bucket, and credentials are required for asset storage.');
        }
        if ($this->maxReadBytes <= 0 || $this->cacheControl === '') {
            throw new InvalidArgumentException('Asset storage read limit and snapshot cache control must be configured.');
        }
        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => $region === '' ? 'auto' : $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($config['path_style_endpoint'] ?? true),
            'credentials' => new Credentials($accessKeyId, $secretAccessKey),
        ]);
    }

    public function read(string $objectKey): string
    {
        $key = (new AssetObjectKey($objectKey))->value;
        $fetched = $this->fetch($key, false);

        return $fetched['body'];
    }

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        $key = (new AssetObjectKey($objectKey))->value;
        $fetched = $this->fetch($key, true);
        if ($fetched === null) {
            return null;
        }
        [$width, $height, $durationSeconds] = $this->geometry($fetched['content_type'], $fetched['body'], $key);

        return new StoredObjectInspection(
            objectKey: $key,
            contentType: $fetched['content_type'],
            byteSize: $fetched['byte_size'],
            width: $width,
            height: $height,
            durationSeconds: $durationSeconds,
            checksum: 'sha256:' . hash('sha256', $fetched['body']),
            leadingBytes: substr($fetched['body'], 0, 512),
            body: $fetched['body'],
        );
    }

    /** @return array{body:string, byte_size:int, content_type:string}|null */
    private function fetch(string $key, bool $missingAsNull): ?array
    {

        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (AwsException $exception) {
            if ($missingAsNull && $this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('Asset object storage metadata read failed.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('Asset object storage metadata read failed.', previous: $exception);
        }
        $length = $this->contentLength($head['ContentLength'] ?? null);
        $this->encryption->assertMetadata(
            $head->toArray(),
            'Asset object does not use the required server-side encryption.',
        );
        $contentType = strtolower(trim(explode(';', (string) ($head['ContentType'] ?? 'application/octet-stream'), 2)[0]));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (AwsException $exception) {
            if ($missingAsNull && $this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('Asset object storage content read failed.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('Asset object storage content read failed.', previous: $exception);
        }

        $body = $result['Body'] ?? null;
        if ($body instanceof StreamInterface) {
            try {
                $contents = $this->readBoundedBody($body);
            } catch (Throwable $exception) {
                throw new RuntimeException('Asset object storage body read failed.', previous: $exception);
            }
        } elseif (is_string($body)) {
            if (strlen($body) > $this->maxReadBytes) {
                throw new RuntimeException('Asset object storage object exceeds the configured read limit.');
            }
            $contents = $body;
        } else {
            throw new RuntimeException('Asset object storage returned no readable object body.');
        }
        if (strlen($contents) > $this->maxReadBytes) {
            throw new RuntimeException('Asset object storage object exceeds the configured read limit.');
        }
        if (strlen($contents) !== $length) {
            throw new RuntimeException('Asset object length changed while it was being read.');
        }

        return ['body' => $contents, 'byte_size' => $length, 'content_type' => $contentType];
    }

    private function readBoundedBody(StreamInterface $body): string
    {
        $contents = '';
        $bytesRead = 0;
        // Always attempt the first read so a transport failure is not hidden by a stale EOF state.
        while ($bytesRead === 0 || (!$body->eof() && $bytesRead <= $this->maxReadBytes)) {
            $chunk = $body->read(min(1_048_576, $this->maxReadBytes + 1 - $bytesRead));
            if ($chunk === '') {
                break;
            }

            $chunkSize = strlen($chunk);
            $bytesRead += $chunkSize;
            $contents .= $chunk;
        }

        return $contents;
    }

    public function putSnapshot(string $objectKey, string $body, string $contentType): void
    {
        $key = (new AssetObjectKey($objectKey))->value;
        $image = $body === '' ? false : @getimagesizefromstring($body);
        if (
            !in_array($contentType, ['image/png', 'image/webp'], true)
            || !is_array($image)
            || ($image['mime'] ?? null) !== $contentType
        ) {
            throw new InvalidArgumentException('Asset snapshot writes require non-empty PNG or WebP bytes.');
        }

        $arguments = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
            'CacheControl' => $this->cacheControl,
            'ContentDisposition' => 'inline',
            'Metadata' => ['sha256' => hash('sha256', $body)],
        ];
        $arguments = array_merge($arguments, $this->encryption->putParameters());

        try {
            $this->client->putObject($arguments);
        } catch (Throwable $exception) {
            throw new RuntimeException('Asset snapshot storage write failed.', previous: $exception);
        }
    }

    private function contentLength(mixed $value): int
    {
        if (is_int($value)) {
            $length = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $length = (int) $value;
        } else {
            throw new RuntimeException('Asset object length is invalid or exceeds the configured read limit.');
        }
        if ($length <= 0 || $length > $this->maxReadBytes) {
            throw new RuntimeException('Asset object length is invalid or exceeds the configured read limit.');
        }

        return $length;
    }

    /** @return array{0:int, 1:int, 2:?float} */
    private function geometry(string $contentType, string $body, string $objectKey): array
    {
        if (str_starts_with($contentType, 'image/')) {
            $image = @getimagesizefromstring($body);

            return [
                is_array($image) ? (int) ($image[0] ?? 0) : 0,
                is_array($image) ? (int) ($image[1] ?? 0) : 0,
                null,
            ];
        }
        if (str_starts_with($contentType, 'video/')) {
            $probe = ($this->videoProbe)($body, $objectKey, $contentType);

            return [
                (int) ($probe['width'] ?? 0),
                (int) ($probe['height'] ?? 0),
                isset($probe['duration_seconds']) ? (float) $probe['duration_seconds'] : null,
            ];
        }

        return [1, 1, null];
    }

    /** @return array{width:int, height:int, duration_seconds:?float} */
    private function defaultVideoProbe(string $body, string $objectKey, string $contentType): array
    {
        $path = ($this->temporaryPathFactory)();
        if ($path === null) {
            return ['width' => 0, 'height' => 0, 'duration_seconds' => null];
        }

        try {
            if (file_put_contents($path, $body) !== strlen($body)) {
                return ['width' => 0, 'height' => 0, 'duration_seconds' => null];
            }
            $analyzer = new \getID3();
            /** @var array<string, mixed> $info */
            $info = $analyzer->analyze($path);
            $video = is_array($info['video'] ?? null) ? $info['video'] : [];
            $width = $video['resolution_x'] ?? 0;
            $height = $video['resolution_y'] ?? 0;
            $duration = $info['playtime_seconds'] ?? null;

            return [
                'width' => is_numeric($width) ? (int) $width : 0,
                'height' => is_numeric($height) ? (int) $height : 0,
                'duration_seconds' => is_numeric($duration) ? (float) $duration : null,
            ];
        } catch (Throwable) {
            return ['width' => 0, 'height' => 0, 'duration_seconds' => null];
        } finally {
            @unlink($path);
        }
    }

    private function isNotFound(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
    }
}
