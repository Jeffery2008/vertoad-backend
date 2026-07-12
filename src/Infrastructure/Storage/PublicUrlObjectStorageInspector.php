<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use InvalidArgumentException;
use RuntimeException;

final readonly class PublicUrlObjectStorageInspector implements ObjectStorageInspectorInterface
{
    private string $publicBaseUrl;
    private int $maxInspectBytes;

    /** @var callable(string): ?StoredObjectFetchResult */
    private \Closure $fetcher;

    /** @var callable(string, string, string): array{width:int, height:int, duration_seconds:?float} */
    private \Closure $videoProbe;

    /** @var callable(): ?string */
    private \Closure $temporaryPathFactory;

    /**
     * @param array<string, mixed> $config
     * @param (callable(string): ?StoredObjectFetchResult)|null $fetcher
     * @param (callable(string, string, string): array{width:int, height:int, duration_seconds:?float})|null $videoProbe
     * @param (callable(): ?string)|null $temporaryPathFactory
     */
    public function __construct(
        array $config,
        ?callable $fetcher = null,
        ?callable $videoProbe = null,
        ?callable $temporaryPathFactory = null,
    )
    {
        $this->publicBaseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
        $this->maxInspectBytes = max(1, (int) ($config['max_inspect_bytes'] ?? 209_715_200));
        $this->fetcher = \Closure::fromCallable($fetcher ?? $this->defaultFetcher(...));
        $this->videoProbe = \Closure::fromCallable($videoProbe ?? $this->defaultVideoProbe(...));
        $this->temporaryPathFactory = \Closure::fromCallable(
            $temporaryPathFactory ?? static fn (): ?string => tempnam(sys_get_temp_dir(), 'vertoad-media-') ?: null,
        );

        if ($this->publicBaseUrl === '') {
            throw new InvalidArgumentException('Object storage public_base_url is required for upload inspection.');
        }
    }

    public function inspect(string $objectKey): ?StoredObjectInspection
    {
        $result = ($this->fetcher)($this->urlFor($objectKey));
        if ($result === null || $result->statusCode === 404) {
            return null;
        }

        if ($result->statusCode < 200 || $result->statusCode >= 300) {
            throw new RuntimeException('Object storage returned HTTP ' . $result->statusCode . ' while inspecting uploaded object.');
        }

        $contentType = $this->contentType($result->headers);
        $byteSize = $this->byteSize($result);
        if ($result->statusCode === 206) {
            $byteSize = $this->completePartialResponseSize($result);
        }
        if ($byteSize <= 0 || $byteSize > $this->maxInspectBytes) {
            throw new RuntimeException('Object storage object exceeds the configured inspection byte limit.');
        }
        if (strlen($result->body) !== $byteSize) {
            throw new RuntimeException('Object storage object length changed during inspection.');
        }
        [$width, $height, $durationSeconds] = $this->geometry($contentType, $result->body, $objectKey);

        return new StoredObjectInspection(
            objectKey: $objectKey,
            contentType: $contentType,
            byteSize: $byteSize,
            width: $width,
            height: $height,
            durationSeconds: $durationSeconds,
            checksum: 'sha256:' . hash('sha256', $result->body),
            leadingBytes: substr($result->body, 0, 512),
            body: $result->body,
        );
    }

    private function urlFor(string $objectKey): string
    {
        return $this->publicBaseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function contentType(array $headers): string
    {
        $value = $this->header($headers, 'content-type') ?? 'application/octet-stream';
        $contentType = strtolower(trim(explode(';', $value, 2)[0]));

        return $contentType === '' ? 'application/octet-stream' : $contentType;
    }

    private function byteSize(StoredObjectFetchResult $result): int
    {
        $header = $this->header($result->headers, 'content-length');
        if ($header !== null && ctype_digit($header)) {
            return (int) $header;
        }

        return strlen($result->body);
    }

    private function completePartialResponseSize(StoredObjectFetchResult $result): int
    {
        $contentRange = $this->header($result->headers, 'content-range');
        if ($contentRange === null || preg_match('/^bytes\s+(\d+)-(\d+)\/(\d+)$/i', $contentRange, $matches) !== 1) {
            throw new RuntimeException('Object storage object length changed during inspection.');
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];
        $total = (int) $matches[3];
        if ($start !== 0 || $end < $start || $end - $start + 1 !== strlen($result->body) || $end + 1 !== $total) {
            throw new RuntimeException('Object storage object length changed during inspection.');
        }

        return $total;
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{0:int, 1:int, 2:?float}
     */
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

        if ($contentType === 'application/json') {
            return $this->jsonGeometry($body);
        }

        if (str_starts_with($contentType, 'video/')) {
            $probe = ($this->videoProbe)($body, $objectKey, $contentType);

            return [(int) $probe['width'], (int) $probe['height'], $probe['duration_seconds']];
        }

        return [1, 1, null];
    }

    /**
     * @return array{0:int, 1:int, 2:null}
     */
    private function jsonGeometry(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [1, 1, null];
        }

        if (!is_array($decoded)) {
            return [1, 1, null];
        }

        $canvas = $decoded['canvas'] ?? $decoded;
        if (!is_array($canvas)) {
            return [1, 1, null];
        }

        $width = $canvas['width'] ?? null;
        $height = $canvas['height'] ?? null;
        if ((is_int($width) || is_float($width)) && (is_int($height) || is_float($height))) {
            return [(int) round((float) $width), (int) round((float) $height), null];
        }

        return [1, 1, null];
    }

    private function defaultFetcher(string $url): ?StoredObjectFetchResult
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => 'Range: bytes=0-' . ($this->maxInspectBytes - 1),
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return null;
        }

        try {
            $contents = stream_get_contents($stream, $this->maxInspectBytes + 1);
            $body = is_string($contents) ? $contents : '';
            $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        } finally {
            fclose($stream);
        }

        return new StoredObjectFetchResult($this->httpStatus($headers), $this->headers($headers), $body);
    }

    /**
     * @param list<string> $headers
     */
    private function httpStatus(array $headers): int
    {
        $statusLine = $headers[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 200;
    }

    /**
     * @param list<string> $rawHeaders
     * @return array<string, string>
     */
    private function headers(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $header) {
            if (!str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }

    /**
     * @return array{width:int, height:int, duration_seconds:?float}
     */
    private function defaultVideoProbe(string $body, string $objectKey, string $contentType): array
    {
        $temp = ($this->temporaryPathFactory)();
        if ($temp === null) {
            return ['width' => 0, 'height' => 0, 'duration_seconds' => null];
        }

        $extension = match ($contentType) {
            'video/mp4' => '.mp4',
            'video/webm' => '.webm',
            default => '',
        };
        $path = $temp . $extension;
        @rename($temp, $path);

        try {
            file_put_contents($path, $body);
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
        } finally {
            @unlink($path);
        }
    }
}
