<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\RequestIdContext;

final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const ALLOWED_HEADERS = [
        'accept',
        'accept-language',
        'authorization',
        'content-language',
        'content-type',
        'x-request-id',
        'x-turnstile-token',
    ];
    private const PUBLIC_PATH_PREFIXES = ['/api/v1/ads/'];

    /** @var array<string, true> */
    private array $allowedOrigins = [];

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        array $allowedOrigins,
    ) {
        foreach ($allowedOrigins as $origin) {
            $normalized = self::normalizeOrigin($origin);
            if ($normalized === null || $normalized === 'null') {
                throw new \InvalidArgumentException('CORS_ALLOWED_ORIGINS contains an invalid HTTP(S) origin.');
            }
            $this->allowedOrigins[$normalized] = true;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return $handler->handle($request);
        }

        $publicPath = $this->isPublicPath($request->getUri()->getPath());
        $normalizedOrigin = self::normalizeOrigin($origin);
        if (!$publicPath && ($normalizedOrigin === null || !isset($this->allowedOrigins[$normalizedOrigin]))) {
            return $this->rejection($request, 'cors_origin_not_allowed', 'The request origin is not allowed.');
        }
        if ($publicPath && $normalizedOrigin === null && $origin !== 'null') {
            return $this->rejection($request, 'cors_origin_invalid', 'The request origin is invalid.');
        }

        $responseOrigin = $publicPath ? '*' : (string) $normalizedOrigin;
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->preflight($request, $responseOrigin);
        }

        return $this->withActualResponseHeaders($handler->handle($request), $responseOrigin);
    }

    private function preflight(ServerRequestInterface $request, string $responseOrigin): ResponseInterface
    {
        $requestedMethod = strtoupper(trim($request->getHeaderLine('Access-Control-Request-Method')));
        if ($requestedMethod === '' || !in_array($requestedMethod, self::ALLOWED_METHODS, true)) {
            return $this->rejection(
                $request,
                'cors_method_not_allowed',
                'The requested cross-origin method is not allowed.',
            );
        }

        $requestedHeaders = $this->requestedHeaders($request->getHeaderLine('Access-Control-Request-Headers'));
        if ($requestedHeaders === null) {
            return $this->rejection(
                $request,
                'cors_headers_not_allowed',
                'One or more requested cross-origin headers are not allowed.',
            );
        }

        $response = $this->responseFactory->createResponse(204)
            ->withHeader('Access-Control-Allow-Origin', $responseOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', self::ALLOWED_METHODS))
            ->withHeader('Access-Control-Max-Age', '600')
            ->withHeader('Cache-Control', 'no-store, max-age=0');
        if ($requestedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $requestedHeaders));
        }

        return $this->withVary($response, [
            'Origin',
            'Access-Control-Request-Method',
            'Access-Control-Request-Headers',
        ]);
    }

    private function withActualResponseHeaders(ResponseInterface $response, string $responseOrigin): ResponseInterface
    {
        return $this->withVary(
            $response
                ->withHeader('Access-Control-Allow-Origin', $responseOrigin)
                ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id'),
            ['Origin'],
        );
    }

    private function rejection(
        ServerRequestInterface $request,
        string $code,
        string $message,
    ): ResponseInterface {
        $requestId = RequestIdContext::ensure($request);
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write(json_encode([
            'data' => null,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['api_version' => 'v1'],
            'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR));

        return $this->withVary(
            $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store, max-age=0')
                ->withHeader('X-Request-Id', $requestId),
            ['Origin'],
        );
    }

    /** @return list<string>|null */
    private function requestedHeaders(string $headerLine): ?array
    {
        if (trim($headerLine) === '') {
            return [];
        }

        $headers = [];
        foreach (explode(',', $headerLine) as $header) {
            $normalized = strtolower(trim($header));
            if ($normalized === '' || !in_array($normalized, self::ALLOWED_HEADERS, true)) {
                return null;
            }
            $headers[$normalized] = true;
        }

        return array_keys($headers);
    }

    private function isPublicPath(string $path): bool
    {
        foreach (self::PUBLIC_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $values */
    private function withVary(ResponseInterface $response, array $values): ResponseInterface
    {
        $vary = [];
        foreach (explode(',', $response->getHeaderLine('Vary')) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $vary[strtolower($value)] = $value;
            }
        }
        foreach ($values as $value) {
            $vary[strtolower($value)] = $value;
        }

        return $response->withHeader('Vary', implode(', ', array_values($vary)));
    }

    private static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === 'null') {
            return 'null';
        }
        if ($origin === '' || str_contains($origin, ',') || preg_match('/\s/', $origin) === 1) {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($host === '' || ($port !== null && ($port < 1 || $port > 65_535))) {
            return null;
        }

        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme . '://' . $host . ($port !== null && !$defaultPort ? ':' . $port : '');
    }
}
