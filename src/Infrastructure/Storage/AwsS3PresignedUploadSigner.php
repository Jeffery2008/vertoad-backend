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

        if ($endpoint === '') {
            throw new InvalidArgumentException('S3 endpoint is required.');
        }

        if ($this->bucket === '') {
            throw new InvalidArgumentException('S3 bucket is required.');
        }

        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new InvalidArgumentException('S3 access key and secret are required.');
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

        $command = $this->client->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $request->objectKey,
            'ContentType' => $request->contentType,
            'Metadata' => [
                'vertoad-byte-size' => (string) $request->byteSize,
            ],
        ]);
        $presigned = $this->client->createPresignedRequest($command, '+' . $ttlSeconds . ' seconds');

        return new PresignedUpload(
            url: (string) $presigned->getUri(),
            method: 'PUT',
            objectKey: $request->objectKey,
            statusCode: 200,
            headers: [
                'Content-Type' => $request->contentType,
                'x-amz-meta-vertoad-byte-size' => (string) $request->byteSize,
            ],
        );
    }
}
