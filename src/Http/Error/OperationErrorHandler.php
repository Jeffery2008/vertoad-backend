<?php

declare(strict_types=1);

namespace VertoAD\Http\Error;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Service\Operations\OperationErrorCaptureService;

final readonly class OperationErrorHandler
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private OperationErrorCaptureService $errors,
        private ?ClientIpResolver $ipResolver = null,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $requestId = $this->requestId($request);
        try {
            $captured = $exception instanceof \Error
                ? $this->errors->capturePhpError($requestId, 'critical', $exception->getMessage(), $this->context($request, $exception), $this->now())
                : $this->errors->captureApiError($requestId, 'error', $exception->getMessage(), $this->context($request, $exception), $this->now());

            $response = $this->responseFactory->createResponse(500);
            $response->getBody()->write(json_encode([
                'data' => null,
                'error' => [
                    'code' => 'operation_error',
                    'message' => 'Internal server error. The incident has been logged for operations review.',
                    'operation_error_id' => $captured['error_id'],
                ],
                'meta' => [
                    'api_version' => 'v1',
                ],
                'request_id' => $requestId,
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Request-Id', $requestId);
        } finally {
            RequestIdContext::clear();
        }
    }

    private function requestId(ServerRequestInterface $request): string
    {
        return RequestIdContext::ensure($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ServerRequestInterface $request, Throwable $exception): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(',', $values);
        }

        return [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'ip_address' => $this->ipResolver?->resolve($request),
            'user_agent' => trim($request->getHeaderLine('User-Agent')) ?: null,
            'headers' => $headers,
            'exception_class' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

}
