<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use InvalidArgumentException;

final readonly class DeterministicPresignedUploadSigner implements ObjectStorageUploadSignerInterface
{
    private string $endpoint;
    private string $bucket;
    private string $accessKeyId;
    private string $secretAccessKey;
    private bool $pathStyleEndpoint;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->bucket = trim((string) ($config['bucket'] ?? ''));
        $this->accessKeyId = trim((string) ($config['access_key_id'] ?? ''));
        $this->secretAccessKey = (string) ($config['secret_access_key'] ?? '');
        $this->pathStyleEndpoint = (bool) ($config['path_style_endpoint'] ?? true);

        if ($this->endpoint === '') {
            throw new InvalidArgumentException('Storage endpoint is required.');
        }

        if ($this->bucket === '') {
            throw new InvalidArgumentException('Storage bucket is required.');
        }

        if ($this->accessKeyId === '' || $this->secretAccessKey === '') {
            throw new InvalidArgumentException('Storage access key and secret are required.');
        }
    }

    public function presignPut(PresignedUploadRequest $request): PresignedUpload
    {
        $expires = $request->expiresAt->getTimestamp();
        $canonical = implode("\n", ['PUT', $this->bucket, $request->objectKey, $request->contentType, (string) $request->byteSize, (string) $expires]);
        $signature = hash_hmac('sha256', $canonical, $this->secretAccessKey);
        $accessKey = $this->accessKeyId === '' ? '' : '***';
        $query = http_build_query([
            'X-VertoAD-Access-Key' => $accessKey,
            'X-VertoAD-Expires' => $expires,
            'X-VertoAD-Signature' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);

        return new PresignedUpload(
            url: $this->baseUrl($request->objectKey) . '?' . $query,
            method: 'PUT',
            objectKey: $request->objectKey,
            statusCode: 201,
            headers: [
                'Content-Type' => $request->contentType,
                'x-amz-meta-vertoad-byte-size' => (string) $request->byteSize,
            ],
        );
    }

    private function baseUrl(string $objectKey): string
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        if ($this->pathStyleEndpoint) {
            return $this->endpoint . '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        }

        $parts = parse_url($this->endpoint);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? $this->endpoint);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . rawurlencode($this->bucket) . '.' . $host . $port . '/' . $encodedKey;
    }
}
