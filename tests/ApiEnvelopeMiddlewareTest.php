<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;

final class ApiEnvelopeMiddlewareTest extends TestCase
{
    public function testLeavesNonJsonResponseBodyUntouchedAndAddsRequestId(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/plain')
            ->withHeader('X-Request-Id', 'plain-request');

        $response = $middleware->process($request, new FixedResponseHandler(
            statusCode: 204,
            body: '',
            headers: ['Content-Type' => 'text/plain'],
        ));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('plain-request', $response->getHeaderLine('X-Request-Id'));
    }

    public function testWrapsInvalidJsonErrorBodyAsEnvelopeError(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/broken-json')
            ->withHeader('X-Request-Id', 'broken-json-request');

        $response = $middleware->process($request, new FixedResponseHandler(
            statusCode: 500,
            body: '{',
            headers: ['Content-Type' => 'application/json', 'Content-Length' => '1'],
        ));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertNull($payload['error']);
        self::assertSame(['api_version' => 'v1'], $payload['meta']);
        self::assertSame('broken-json-request', $payload['request_id']);
        self::assertSame('', $response->getHeaderLine('Content-Length'));
    }

    public function testKeepsExistingEnvelopeAndRequestId(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/already-enveloped')
            ->withHeader('X-Request-Id', 'existing-envelope-request');
        $body = json_encode([
            'data' => ['status' => 'ok'],
            'error' => null,
            'meta' => ['api_version' => 'v1'],
            'request_id' => 'upstream-request',
        ], JSON_THROW_ON_ERROR);

        $response = $middleware->process($request, new FixedResponseHandler(
            statusCode: 200,
            body: $body,
            headers: ['Content-Type' => 'application/json'],
        ));

        self::assertSame($body, (string) $response->getBody());
        self::assertSame('existing-envelope-request', $response->getHeaderLine('X-Request-Id'));
    }
}

final class FixedResponseHandler implements RequestHandlerInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
        private readonly array $headers,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($this->statusCode);
        $response->getBody()->write($this->body);

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
