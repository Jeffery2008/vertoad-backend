<?php

declare(strict_types=1);

namespace VertoAD\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\RuntimeConfigHealthCheck;

final class HealthAction
{
    public function __construct(private readonly ?RuntimeConfigHealthCheck $runtimeConfigHealthCheck = null)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->runtimeConfigHealthCheck?->assertHealthy();
        } catch (\Throwable $exception) {
            $response->getBody()->write(json_encode([
                'code' => 'runtime_config_unhealthy',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'service' => 'vertoad-api',
            'environment' => getenv('APP_ENV') ?: 'local',
            'timestamp' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
