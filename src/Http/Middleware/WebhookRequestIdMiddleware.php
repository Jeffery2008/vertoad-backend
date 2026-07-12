<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\RequestIdContext;

final readonly class WebhookRequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldBind($request) || !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return $handler->handle($request);
        }

        $requestId = substr((string) RequestIdContext::fromRequest($request), 0, 160);
        $this->connection->executeStatement('SET @vertoad_request_id = ?', [$requestId === '' ? null : $requestId]);

        try {
            return $handler->handle($request);
        } finally {
            $this->connection->executeStatement('SET @vertoad_request_id = NULL');
        }
    }

    private function shouldBind(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/v1/')) {
            return false;
        }

        return !in_array(strtoupper($request->getMethod()), ['HEAD', 'OPTIONS'], true);
    }
}
