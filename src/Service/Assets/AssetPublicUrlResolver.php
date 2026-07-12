<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use InvalidArgumentException;
use VertoAD\Domain\Assets\AssetObjectKey;

final readonly class AssetPublicUrlResolver
{
    private string $baseUrl;
    private string $origin;
    private string $basePath;

    public function __construct(string $baseUrl, bool $allowHttp = false)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        if (
            !is_array($parts)
            || !in_array($scheme, $allowHttp ? ['http', 'https'] : ['https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Asset public base URL must be an allowed absolute origin/base URL without credentials, query, or fragment.');
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && $path !== '/' && $this->hasUnsafeEncodedPathSegment($path)) {
            throw new InvalidArgumentException('Asset public base URL contains an unsafe path.');
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $this->baseUrl = $baseUrl;
        $this->origin = $scheme . '://' . strtolower($host) . $port;
        $this->basePath = $path === '/' ? '' : $path;
    }

    public function urlFor(string $objectKey): string
    {
        return $this->baseUrl . '/' . (new AssetObjectKey($objectKey))->encodedPath();
    }

    public function isTrustedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        if ($scheme . '://' . $host . $port !== $this->origin) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        $prefix = $this->basePath . '/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $encodedRelativePath = substr($path, strlen($prefix));
        if ($encodedRelativePath === '' || $this->hasUnsafeEncodedPathSegment('/' . $encodedRelativePath)) {
            return false;
        }

        $decodedSegments = [];
        foreach (explode('/', $encodedRelativePath) as $encodedSegment) {
            $decoded = rawurldecode($encodedSegment);
            if (rawurlencode($decoded) !== $encodedSegment) {
                return false;
            }
            $decodedSegments[] = $decoded;
        }

        try {
            return $this->urlFor(implode('/', $decodedSegments)) === $url;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function cspSource(): string
    {
        return $this->origin;
    }

    private function hasUnsafeEncodedPathSegment(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);
            if (
                $segment === ''
                || $decoded === '.'
                || $decoded === '..'
                || str_contains($decoded, '/')
                || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
