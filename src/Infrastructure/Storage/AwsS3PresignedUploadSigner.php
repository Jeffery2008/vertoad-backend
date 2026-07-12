<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use VertoAD\Domain\Assets\AssetObjectKey;

final readonly class AwsS3PresignedUploadSigner implements ObjectStorageUploadSignerInterface, ObjectStorageAssetFinalizerInterface
{
    private S3Client $client;
    private string $bucket;
    private int $maxReadBytes;
    private string $immutableCacheControl;
    private ?string $serverSideEncryption;
    /** @var callable(): DateTimeImmutable */
    private mixed $clock;

    /**
     * @param array<string, mixed> $config
     * @param (callable(): DateTimeImmutable)|null $clock
     */
    public function __construct(array $config, ?callable $clock = null, ?S3Client $client = null)
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $region = trim((string) ($config['region'] ?? 'auto'));
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $this->maxReadBytes = (int) ($config['max_read_bytes'] ?? 262_144_000);
        $this->immutableCacheControl = trim((string) (
            $config['asset_cache_control']
            ?? 'public, max-age=31536000, immutable'
        ));
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
        if ($this->maxReadBytes <= 0 || $this->immutableCacheControl === '') {
            throw new InvalidArgumentException('S3 asset finalization limits and cache control must be configured.');
        }
        if ($this->serverSideEncryption !== null && !in_array($this->serverSideEncryption, ['AES256', 'aws:kms'], true)) {
            throw new InvalidArgumentException('S3 server-side encryption must be AES256 or aws:kms.');
        }

        $this->client = $client ?? new S3Client([
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
        if ($request->stagingOnly) {
            $this->assertAssetStagingKey($request->objectKey);
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

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        $key = (new AssetObjectKey($objectKey))->value;

        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (\Aws\Exception\AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('S3 asset object inspection failed.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('S3 asset object inspection failed.', previous: $exception);
        }

        $contentLength = $head['ContentLength'] ?? null;
        if (is_int($contentLength)) {
            $byteSize = $contentLength;
        } elseif (is_string($contentLength) && ctype_digit($contentLength)) {
            $byteSize = (int) $contentLength;
        } else {
            throw new RuntimeException('S3 asset object returned an invalid content length.');
        }
        if ($byteSize <= 0 || $byteSize > $this->maxReadBytes) {
            throw new RuntimeException('S3 asset object exceeds the configured read limit.');
        }
        $contentType = strtolower(trim(explode(';', (string) ($head['ContentType'] ?? ''), 2)[0]));
        if ($contentType === '') {
            throw new RuntimeException('S3 asset object returned no content type.');
        }
        if (
            $this->serverSideEncryption !== null
            && trim((string) ($head['ServerSideEncryption'] ?? '')) !== $this->serverSideEncryption
        ) {
            throw new RuntimeException('S3 asset object does not use the required server-side encryption.');
        }

        try {
            $object = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $body = $object['Body'] ?? null;
        } catch (\Aws\Exception\AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException('S3 asset object content inspection failed.', previous: $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('S3 asset object content inspection failed.', previous: $exception);
        }
        if (!$body instanceof StreamInterface && !is_string($body)) {
            throw new RuntimeException('S3 asset object returned no readable body.');
        }

        $hash = hash_init('sha256');
        $leadingBytes = '';
        $actualByteSize = 0;
        try {
            if (is_string($body)) {
                $actualByteSize = strlen($body);
                hash_update($hash, $body);
                $leadingBytes = substr($body, 0, 512);
            } else {
                while (!$body->eof()) {
                    $chunk = $body->read(1_048_576);
                    if ($chunk === '') {
                        break;
                    }

                    $actualByteSize += strlen($chunk);
                    if ($actualByteSize > $this->maxReadBytes) {
                        throw new RuntimeException('S3 asset object exceeds the configured read limit.');
                    }
                    hash_update($hash, $chunk);
                    if (strlen($leadingBytes) < 512) {
                        $leadingBytes .= substr($chunk, 0, 512 - strlen($leadingBytes));
                    }
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('S3 asset object content inspection failed.', previous: $exception);
        }
        if ($actualByteSize !== $byteSize) {
            throw new RuntimeException('S3 asset object length changed during inspection.');
        }

        return new StoredObjectInspection(
            objectKey: $key,
            contentType: $contentType,
            byteSize: $byteSize,
            width: 1,
            height: 1,
            durationSeconds: null,
            checksum: 'sha256:' . hash_final($hash),
            leadingBytes: $leadingBytes,
            body: null,
        );
    }

    public function writeFinalFromValidatedBytes(
        StoredObjectInspection $stagingObject,
        string $finalObjectKey,
    ): StoredObjectInspection {
        $stagingObjectKey = (new AssetObjectKey($stagingObject->objectKey))->value;
        $finalObjectKey = (new AssetObjectKey($finalObjectKey))->value;
        if ($stagingObjectKey === $finalObjectKey) {
            throw new InvalidArgumentException('S3 asset final key must differ from its staging key.');
        }
        if ($stagingObject->body === null || $stagingObject->body === '') {
            throw new InvalidArgumentException('S3 asset finalization requires authoritative object bytes.');
        }
        $expectedChecksum = 'sha256:' . hash('sha256', $stagingObject->body);
        if (
            $stagingObject->byteSize !== strlen($stagingObject->body)
            || $stagingObject->checksum === null
            || !hash_equals($expectedChecksum, strtolower(trim($stagingObject->checksum)))
        ) {
            throw new InvalidArgumentException('S3 asset staging metadata does not match its bytes.');
        }
        $this->assertAssetFinalKey($stagingObjectKey, $finalObjectKey, $expectedChecksum);

        $parameters = [
            'Bucket' => $this->bucket,
            'Key' => $finalObjectKey,
            'Body' => $stagingObject->body,
            'ContentType' => $stagingObject->contentType,
            'CacheControl' => $this->immutableCacheControl,
            'ContentDisposition' => 'inline',
            'Metadata' => ['sha256' => substr($expectedChecksum, strlen('sha256:'))],
        ];
        if ($this->serverSideEncryption !== null) {
            $parameters['ServerSideEncryption'] = $this->serverSideEncryption;
        }

        try {
            $this->client->putObject($parameters);
        } catch (Throwable $exception) {
            throw new RuntimeException('S3 asset object promotion failed.', previous: $exception);
        }

        $written = $this->inspect($finalObjectKey);
        if (
            $written === null
            || $written->contentType !== $stagingObject->contentType
            || $written->byteSize !== $stagingObject->byteSize
            || $written->checksum === null
            || !hash_equals($expectedChecksum, $written->checksum)
        ) {
            throw new RuntimeException('S3 promoted asset verification failed.');
        }

        return new StoredObjectInspection(
            objectKey: $finalObjectKey,
            contentType: $written->contentType,
            byteSize: $written->byteSize,
            width: $stagingObject->width,
            height: $stagingObject->height,
            durationSeconds: $stagingObject->durationSeconds,
            checksum: $written->checksum,
            leadingBytes: $written->leadingBytes,
            body: $stagingObject->body,
        );
    }

    public function delete(string $objectKey): void
    {
        $objectKey = (new AssetObjectKey($objectKey))->value;

        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $objectKey]);
        } catch (Throwable $exception) {
            throw new RuntimeException('S3 asset object deletion failed.', previous: $exception);
        }
    }

    private function isNotFound(\Aws\Exception\AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
    }

    private function assertAssetStagingKey(string $objectKey): void
    {
        if (preg_match(
            '~^organizations/[1-9][0-9]*/assets/staging/[A-Za-z0-9_-]{8,128}\\.[a-z0-9]+$~D',
            $objectKey,
        ) !== 1) {
            throw new InvalidArgumentException('S3 asset presigned uploads must target a staging object key.');
        }
    }

    private function assertAssetFinalKey(
        string $stagingObjectKey,
        string $finalObjectKey,
        string $expectedChecksum,
    ): void
    {
        if (preg_match(
            '~^organizations/([1-9][0-9]*)/assets/staging/([A-Za-z0-9_-]{1,128})\\.([a-z0-9]+)$~D',
            $stagingObjectKey,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('S3 asset staging key is invalid.');
        }

        $expectedFinalPattern = '~^organizations/' . preg_quote($matches[1], '~')
            . '/assets/final/' . preg_quote($matches[2], '~')
            . '/[A-Za-z0-9_-]{16,128}-sha256-([a-f0-9]{64})\\.' . preg_quote($matches[3], '~') . '$~D';
        if (preg_match($expectedFinalPattern, $finalObjectKey, $finalMatches) !== 1) {
            throw new InvalidArgumentException(
                'S3 asset final key must be a server-generated content-bound final key for its staging object.',
            );
        }
        if (!hash_equals(substr($expectedChecksum, strlen('sha256:')), strtolower($finalMatches[1]))) {
            throw new InvalidArgumentException('S3 asset final key checksum does not match validated bytes.');
        }
    }
}
