<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;

final readonly class S3BackupObjectStorage implements BackupObjectStorageInterface
{
    private S3Client $client;
    private string $bucket;
    private string $serverSideEncryption;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, ?S3Client $client = null)
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $this->serverSideEncryption = trim((string) ($config['server_side_encryption'] ?? 'AES256'));

        if ($endpoint === '') {
            throw new InvalidArgumentException('S3 endpoint is required for backup storage.');
        }
        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 bucket is required for backup storage.');
        }
        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('S3 credentials are required for backup storage.');
        }
        if (!in_array($this->serverSideEncryption, ['AES256', 'aws:kms'], true)) {
            throw new InvalidArgumentException('Backup S3 server-side encryption must be AES256 or aws:kms.');
        }

        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => $region === '' ? 'auto' : $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($config['path_style_endpoint'] ?? true),
            'credentials' => new Credentials($accessKeyId, $secretAccessKey),
        ]);
    }

    public function putFile(string $objectKey, string $localPath, string $contentType): void
    {
        $stream = @fopen($localPath, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Backup source file is not readable.');
        }

        try {
            $this->put($objectKey, $stream, $contentType);
        } finally {
            fclose($stream);
        }
    }

    public function putString(string $objectKey, string $body, string $contentType): void
    {
        $this->put($objectKey, $body, $contentType);
    }

    public function getFile(string $objectKey, string $localPath): void
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $result = $this->client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $localPath,
        ]);
        if (!is_file($localPath) && isset($result['Body'])) {
            if (@file_put_contents($localPath, (string) $result['Body']) === false) {
                throw new RuntimeException('Unable to write downloaded backup object.');
            }
        }
        if (!is_file($localPath)) {
            throw new RuntimeException('Backup object download did not create a local file.');
        }
    }

    public function readString(string $objectKey): string
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $result = $this->client->getObject(['Bucket' => $bucket, 'Key' => $key]);

        return (string) $result['Body'];
    }

    public function copy(string $sourceObjectKey, string $destinationObjectKey): void
    {
        [$sourceBucket, $sourceKey] = $this->parseObjectKey($sourceObjectKey);
        [$destinationBucket, $destinationKey] = $this->parseObjectKey($destinationObjectKey);
        $this->client->copyObject([
            'Bucket' => $destinationBucket,
            'Key' => $destinationKey,
            'CopySource' => $this->copySource($sourceBucket, $sourceKey),
            'ServerSideEncryption' => $this->serverSideEncryption,
        ]);
    }

    public function exists(string $objectKey): bool
    {
        try {
            $this->head($objectKey);

            return true;
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404 || $exception->getAwsErrorCode() === 'NoSuchKey') {
                return false;
            }

            throw $exception;
        }
    }

    public function size(string $objectKey): int
    {
        return (int) ($this->head($objectKey)['ContentLength'] ?? 0);
    }

    public function sha256(string $objectKey): string
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $body = $this->client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'] ?? null;
        if (!$body instanceof StreamInterface) {
            throw new RuntimeException('Backup object body is not a readable stream.');
        }

        $hash = hash_init('sha256');
        while (!$body->eof()) {
            hash_update($hash, $body->read(1024 * 1024));
        }

        return hash_final($hash);
    }

    private function put(string $objectKey, mixed $body, string $contentType): void
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);
        $this->client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
            'ServerSideEncryption' => $this->serverSideEncryption,
        ]);
    }

    /** @return array<string, mixed> */
    private function head(string $objectKey): array
    {
        [$bucket, $key] = $this->parseObjectKey($objectKey);

        return $this->client->headObject(['Bucket' => $bucket, 'Key' => $key])->toArray();
    }

    /** @return array{0:string, 1:string} */
    private function parseObjectKey(string $objectKey): array
    {
        $trimmed = trim($objectKey);
        if (str_starts_with($trimmed, 's3://')) {
            $withoutScheme = substr($trimmed, 5);
            $separator = strpos($withoutScheme, '/');
            if ($separator === false || $separator === 0 || $separator === strlen($withoutScheme) - 1) {
                throw new InvalidArgumentException('Backup object key must be a relative key or s3://bucket/key URI.');
            }

            return [substr($withoutScheme, 0, $separator), substr($withoutScheme, $separator + 1)];
        }
        if ($trimmed === '' || str_contains($trimmed, '://')) {
            throw new InvalidArgumentException('Backup object key must be a relative key or s3://bucket/key URI.');
        }

        return [$this->bucket, ltrim($trimmed, '/')];
    }

    private function copySource(string $bucket, string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $bucket . '/' . $key)));
    }
}
