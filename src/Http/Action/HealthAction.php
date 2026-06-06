<?php

declare(strict_types=1);

namespace VertoAD\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'service' => 'vertoad-api',
            'environment' => getenv('APP_ENV') ?: 'local',
            'timestamp' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
