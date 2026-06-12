<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use InvalidArgumentException;
use VertoAD\Service\Archive\ArchiveObjectStorageInterface;

final readonly class S3ArchiveObjectStorage implements ArchiveObjectStorageInterface
{
    private S3Client $client;
    private string $bucket;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?S3Client $client = null)
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');

        if ($endpoint === '') {
            throw new InvalidArgumentException('S3 endpoint is required.');
        }

        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 bucket is required.');
        }

        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('S3 access key and secret are required.');
        }

        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => $region === '' ? 'auto' : $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($config['path_style_endpoint'] ?? true),
            'credentials' => new Credentials($accessKeyId, $secretAccessKey),
        ]);
    }

    public function put(string $objectKey, string $body, string $contentType): void
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $this->client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
        ]);
    }

    public function get(string $objectKey): string
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $result = $this->client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function parseObjectKey(string $objectKey): array
    {
        $trimmed = trim($objectKey);
        if (str_starts_with($trimmed, 's3://')) {
            $withoutScheme = substr($trimmed, 5);
            $separator = strpos($withoutScheme, '/');
            if ($separator === false || $separator === 0 || $separator === strlen($withoutScheme) - 1) {
                throw new InvalidArgumentException('Archive object key must be a relative key or s3://bucket/key URI.');
            }

            return [substr($withoutScheme, 0, $separator), substr($withoutScheme, $separator + 1)];
        }

        if ($trimmed === '' || str_contains($trimmed, '://')) {
            throw new InvalidArgumentException('Archive object key must be a relative key or s3://bucket/key URI.');
        }

        return [$this->bucket, ltrim($trimmed, '/')];
    }
}
