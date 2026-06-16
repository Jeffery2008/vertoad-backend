<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Middleware\LazyContainerMiddleware;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\RequestIdContext;

final class ApiEnvelopeMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

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

    public function testWrapsEmptyJsonSuccessBodyAsNullDataEnvelope(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/empty-json')
            ->withHeader('X-Request-Id', 'empty-json-request');

        $response = $middleware->process($request, new FixedResponseHandler(
            statusCode: 200,
            body: '',
            headers: ['Content-Type' => 'application/json', 'Content-Length' => '0'],
        ));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertNull($payload['error']);
        self::assertSame(['api_version' => 'v1'], $payload['meta']);
        self::assertSame('empty-json-request', $payload['request_id']);
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

    public function testKeepsExistingEnvelopeResponseRequestIdHeaderWhenPresent(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/already-enveloped')
            ->withHeader('X-Request-Id', 'request-header-id');
        $body = json_encode([
            'data' => null,
            'error' => ['code' => 'turnstile_token_required', 'message' => 'Turnstile token is required.'],
            'meta' => ['api_version' => 'v1'],
            'request_id' => 'response-header-id',
        ], JSON_THROW_ON_ERROR);

        $response = $middleware->process($request, new FixedResponseHandler(
            statusCode: 400,
            body: $body,
            headers: ['Content-Type' => 'application/json', 'X-Request-Id' => 'response-header-id'],
        ));

        self::assertSame($body, (string) $response->getBody());
        self::assertSame('response-header-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testGeneratedRequestIdIsAvailableAsCurrentContextInEnvelopeOnlyStacks(): void
    {
        $middleware = new ApiEnvelopeMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/generated-request-id');
        $observed = [];

        $response = $middleware->process($request, new class($observed) implements RequestHandlerInterface {
            /** @param array<string,string|null> $observed */
            public function __construct(private array &$observed)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->observed['from_request'] = RequestIdContext::fromRequest($request);
                $this->observed['current'] = RequestIdContext::current();

                $response = (new ResponseFactory())->createResponse(200);
                $response->getBody()->write(json_encode(['ok' => true], JSON_THROW_ON_ERROR));

                return $response->withHeader('Content-Type', 'application/json');
            }
        });
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) ($payload['request_id'] ?? ''));
        self::assertSame($payload['request_id'], $response->getHeaderLine('X-Request-Id'));
        self::assertSame($payload['request_id'], $observed['from_request'] ?? null);
        self::assertSame($payload['request_id'], $observed['current'] ?? null);
        self::assertNull(RequestIdContext::current());
    }

    public function testLazyContainerSkipsResolutionWhenEndpointIsNotEnabled(): void
    {
        $middleware = new LazyContainerMiddleware(
            new class implements \Psr\Container\ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \RuntimeException('container should not be consulted');
                }

                public function has(string $id): bool
                {
                    return true;
                }
            },
            'test.middleware',
            ['POST:/enabled'],
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/disabled')
            ->withAttribute(RequestIdContext::ATTRIBUTE, 'attr-request-id')
            ->withHeader('X-Request-Id', 'header-request-id');
        $handled = false;

        self::assertSame('attr-request-id', RequestIdContext::begin($request));

        $response = $middleware->process($request, new class($handled) implements RequestHandlerInterface {
            public function __construct(private bool &$handled)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = true;
                TestCase::assertSame('attr-request-id', RequestIdContext::fromRequest($request));

                return (new ResponseFactory())->createResponse(204);
            }
        });

        self::assertTrue($handled);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testLazyContainerResolvesAndDelegatesWhenEndpointIsEnabled(): void
    {
        $delegateCalled = false;
        $middleware = new LazyContainerMiddleware(
            new class($delegateCalled) implements \Psr\Container\ContainerInterface {
                public function __construct(private bool &$delegateCalled)
                {
                }

                public function get(string $id): mixed
                {
                    TestCase::assertSame('test.middleware', $id);

                    return new class($this->delegateCalled) implements \Psr\Http\Server\MiddlewareInterface {
                        public function __construct(private bool &$delegateCalled)
                        {
                        }

                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            $this->delegateCalled = true;

                            return (new ResponseFactory())->createResponse(202);
                        }
                    };
                }

                public function has(string $id): bool
                {
                    return $id === 'test.middleware';
                }
            },
            'test.middleware',
            ['get:/enabled'],
        );
        $handlerCalled = false;

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/ENABLED'),
            new class($handlerCalled) implements RequestHandlerInterface {
                public function __construct(private bool &$handlerCalled)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->handlerCalled = true;

                    return (new ResponseFactory())->createResponse(500);
                }
            },
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($delegateCalled);
        self::assertFalse($handlerCalled);
    }

    public function testLazyContainerRejectsResolvedNonMiddleware(): void
    {
        $middleware = new LazyContainerMiddleware(
            new class implements \Psr\Container\ContainerInterface {
                public function get(string $id): mixed
                {
                    return new \stdClass();
                }

                public function has(string $id): bool
                {
                    return true;
                }
            },
            'bad.middleware',
            ['GET:/enabled'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad.middleware must resolve to a PSR-15 middleware.');

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/ENABLED'),
            new FixedResponseHandler(200, '', []),
        );
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
