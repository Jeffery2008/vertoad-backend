<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\Auth\RequestUserContext;

final readonly class SupportTicketListQueryMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = $request->getQueryParams();
        if (!array_key_exists('organization_id', $query)) {
            return $handler->handle($request);
        }

        if (RequestUserContext::fromRequest($request)->user === null) {
            return $handler->handle($request);
        }

        if ($this->positiveIntegerQuery($query['organization_id']) !== null) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(422);
        $response->getBody()->write(json_encode([
            'code' => 'invalid_request',
            'message' => 'organization_id must be a positive integer query parameter.',
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function positiveIntegerQuery(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
