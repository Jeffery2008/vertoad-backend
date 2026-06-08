<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;

final readonly class OperationsJson
{
    /**
     * @param array<string, mixed>|list<array<string, mixed>> $payload
     */
    public static function write(ResponseInterface $response, array $payload, int $statusCode = 200): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
