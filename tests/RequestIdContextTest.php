<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Middleware\RequestIdMiddleware;
use VertoAD\Http\RequestIdContext;

final class RequestIdContextTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

    public function testBeginUsesInboundHeaderInsteadOfStaleCurrentRequestId(): void
    {
        $factory = new ServerRequestFactory();
        $first = $factory->createServerRequest('GET', '/first')
            ->withHeader('X-Request-Id', 'req-first');
        $second = $factory->createServerRequest('GET', '/second')
            ->withHeader('X-Request-Id', 'req-second');

        RequestIdContext::begin($first);

        self::assertSame('req-second', RequestIdContext::begin($second));
        self::assertSame('req-second', RequestIdContext::current());
    }

    public function testRequestIdMiddlewareClearsCurrentContextWhenDownstreamThrows(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/throws')
            ->withHeader('X-Request-Id', 'req-throw-cleanup');
        $middleware = new RequestIdMiddleware();

        try {
            $middleware->process($request, new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    TestCase::assertSame('req-throw-cleanup', RequestIdContext::current());

                    throw new \RuntimeException('downstream failed');
                }
            });
            self::fail('Expected downstream exception to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('downstream failed', $exception->getMessage());
        }

        self::assertNull(RequestIdContext::current());
        self::assertSame('req-throw-cleanup', RequestIdContext::ensure((new ServerRequestFactory())->createServerRequest('GET', '/outer-error-handler')));
        self::assertSame('req-throw-cleanup', RequestIdContext::current());
        RequestIdContext::clear();
        self::assertNull(RequestIdContext::current());
    }
}
