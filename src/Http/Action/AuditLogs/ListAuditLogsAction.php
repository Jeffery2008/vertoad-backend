<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\AuditLogs;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\AuditLogService;

final readonly class ListAuditLogsAction
{
    public function __construct(private AuditLogService $auditLogs)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $payload = $this->auditLogs->search($request->getQueryParams(), RequestUserContext::fromRequest($request));
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, [
                'code' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return $this->json($response, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode = 200): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
