<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AwsS3PresignedUploadSigner implements ObjectStorageUploadSignerInterface
{
    private S3Client $client;
    private string $bucket;
    private ?string $serverSideEncryption;
    /** @var callable(): DateTimeImmutable */
    private mixed $clock;

    /**
     * @param array<string, mixed> $config
     * @param (callable(): DateTimeImmutable)|null $clock
     */
    public function __construct(array $config, ?callable $clock = null)
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $serverSideEncryption = trim((string) ($config['server_side_encryption'] ?? ''));
        $this->serverSideEncryption = $serverSideEncryption === '' ? null : $serverSideEncryption;

        if ($endpoint === '') {
            throw new InvalidArgumentException('S3 endpoint is required.');
        }

        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 bucket is required.');
        }

        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('S3 access key and secret are required.');
        }
        if ($this->serverSideEncryption !== null && !in_array($this->serverSideEncryption, ['AES256', 'aws:kms'], true)) {
            throw new InvalidArgumentException('S3 server-side encryption must be AES256 or aws:kms.');
        }

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $region === '' ? 'auto' : $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) ($config['path_style_endpoint'] ?? true),
            'credentials' => new Credentials($accessKeyId, $secretAccessKey),
        ]);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function presignPut(PresignedUploadRequest $request): PresignedUpload
    {
        $now = ($this->clock)();
        $ttlSeconds = $request->expiresAt->getTimestamp() - $now->getTimestamp();
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('S3 presigned upload expiry must be in the future.');
        }

        $parameters = [
            'Bucket' => $this->bucket,
            'Key' => $request->objectKey,
            'ContentType' => $request->contentType,
            'Metadata' => [
                'vertoad-byte-size' => (string) $request->byteSize,
            ],
        ];
        if ($this->serverSideEncryption !== null) {
            $parameters['ServerSideEncryption'] = $this->serverSideEncryption;
        }
        $command = $this->client->getCommand('PutObject', $parameters);
        $presigned = $this->client->createPresignedRequest($command, '+' . $ttlSeconds . ' seconds');

        $headers = [
            'Content-Type' => $request->contentType,
            'x-amz-meta-vertoad-byte-size' => (string) $request->byteSize,
        ];
        if ($this->serverSideEncryption !== null) {
            $headers['x-amz-server-side-encryption'] = $this->serverSideEncryption;
        }

        return new PresignedUpload(
            url: (string) $presigned->getUri(),
            method: 'PUT',
            objectKey: $request->objectKey,
            statusCode: 200,
            headers: $headers,
        );
    }
}
