<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final readonly class S3ObjectStorageInspector implements ObjectStorageInspectorInterface
{
    private S3Client $client;
    private string $bucket;
    private int $maxInspectBytes;
    private ?string $serverSideEncryption;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, ?S3Client $client = null)
    {
        $endpoint = rtrim(trim((string) ($config['endpoint'] ?? '')), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $this->maxInspectBytes = (int) ($config['max_inspect_bytes'] ?? 10_485_760);
        $serverSideEncryption = trim((string) ($config['server_side_encryption'] ?? ''));
        $this->serverSideEncryption = $serverSideEncryption === '' ? null : $serverSideEncryption;

        if ($endpoint === '') {
            throw new InvalidArgumentException('S3 endpoint is required for private object inspection.');
        }
        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 bucket is required for private object inspection.');
        }
        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('S3 credentials are required for private object inspection.');
        }
        if ($this->maxInspectBytes <= 0) {
            throw new InvalidArgumentException('S3 private object inspection byte limit must be positive.');
        }
        if ($this->serverSideEncryption !== null && !in_array($this->serverSideEncryption, ['AES256', 'aws:kms'], true)) {
            throw new InvalidArgumentException('S3 private object server-side encryption must be AES256 or aws:kms.');
        }

        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => $region === '' ? 'auto' : $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($config['path_style_endpoint'] ?? true),
            'credentials' => new Credentials($accessKeyId, $secretAccessKey),
        ]);
    }

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        $objectKey = $this->objectKey($objectKey);

        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $objectKey]);
        } catch (AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('Private object storage metadata inspection failed.', previous: $exception);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Private object storage metadata inspection failed.', previous: $exception);
        }

        $contentLength = $head['ContentLength'] ?? null;
        if (!is_numeric($contentLength) || (int) $contentLength < 0) {
            throw new RuntimeException('Private object storage returned an invalid content length.');
        }
        $byteSize = (int) $contentLength;
        if ($byteSize > $this->maxInspectBytes) {
            throw new RuntimeException('Private object exceeds the configured inspection byte limit.');
        }
        if (
            $this->serverSideEncryption !== null
            && trim((string) ($head['ServerSideEncryption'] ?? '')) !== $this->serverSideEncryption
        ) {
            throw new RuntimeException('Private object does not use the required server-side encryption.');
        }

        try {
            $object = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $objectKey]);
        } catch (AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('Private object storage content inspection failed.', previous: $exception);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Private object storage content inspection failed.', previous: $exception);
        }

        $body = $object['Body'] ?? null;
        if ($body instanceof StreamInterface) {
            $contents = $body->getContents();
        } elseif (is_string($body)) {
            $contents = $body;
        } else {
            throw new RuntimeException('Private object storage returned no readable object body.');
        }
        if (strlen($contents) !== $byteSize) {
            throw new RuntimeException('Private object storage content length changed during inspection.');
        }

        $contentType = strtolower(trim(explode(';', (string) ($head['ContentType'] ?? 'application/octet-stream'), 2)[0]));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        return new StoredObjectInspection(
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            width: 1,
            height: 1,
            durationSeconds: null,
            checksum: 'sha256:' . hash('sha256', $contents),
            leadingBytes: substr($contents, 0, 512),
            body: $contents,
        );
    }

    private function objectKey(string $objectKey): string
    {
        $objectKey = trim($objectKey);
        $segments = explode('/', $objectKey);
        if (
            $objectKey === ''
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException('Private object key must be a safe relative key.');
        }

        return $objectKey;
    }

    private function isNotFound(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
    }
}
