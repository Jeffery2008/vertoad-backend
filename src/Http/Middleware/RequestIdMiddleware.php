<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\RequestIdContext;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = RequestIdContext::begin($request);
        $request = $request
            ->withAttribute(RequestIdContext::ATTRIBUTE, $requestId)
            ->withHeader('X-Request-Id', $requestId);

        try {
            $response = $handler->handle($request)->withHeader('X-Request-Id', $requestId);

            return $response;
        } catch (\Throwable $throwable) {
            RequestIdContext::deferForErrorHandler($requestId);

            throw $throwable;
        } finally {
            RequestIdContext::clear($requestId);
        }
    }
}
