<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\RequestIdContext;

final class ApiEnvelopeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = RequestIdContext::ensure($request);
        $request = $request
            ->withAttribute(RequestIdContext::ATTRIBUTE, $requestId)
            ->withHeader('X-Request-Id', $requestId);
        try {
            $response = $handler->handle($request);

            if (!$this->isJsonResponse($response)) {
                return $response->withHeader('X-Request-Id', $requestId);
            }

            $decoded = $this->decodeBody((string) $response->getBody());
            if ($this->isEnvelope($decoded)) {
                $responseRequestId = trim($response->getHeaderLine('X-Request-Id'));

                return $response->withHeader('X-Request-Id', $responseRequestId !== '' ? $responseRequestId : $requestId);
            }

            $envelope = [
                'data' => $response->getStatusCode() < 400 ? $decoded : null,
                'error' => $response->getStatusCode() >= 400 ? $decoded : null,
                'meta' => [
                    'api_version' => 'v1',
                ],
                'request_id' => $requestId,
            ];

            $wrapped = $this->responseFactory->createResponse($response->getStatusCode());
            $wrapped->getBody()->write(json_encode($envelope, JSON_THROW_ON_ERROR));

            foreach ($response->getHeaders() as $name => $values) {
                if (strtolower($name) === 'content-length') {
                    continue;
                }

                $wrapped = $wrapped->withHeader($name, $values);
            }

            return $wrapped
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Request-Id', $requestId);
        } finally {
            RequestIdContext::clear($requestId);
        }
    }

    private function isJsonResponse(ResponseInterface $response): bool
    {
        return str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json');
    }

    private function decodeBody(string $body): mixed
    {
        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param mixed $payload
     */
    private function isEnvelope(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('data', $payload)
            && array_key_exists('error', $payload)
            && array_key_exists('meta', $payload)
            && array_key_exists('request_id', $payload);
    }
}
