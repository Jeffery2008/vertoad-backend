<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Organizations;

use Psr\Http\Message\ResponseInterface;

final class OrganizationMemberSerializers
{
    /** @param array<string, mixed> $payload */
    public static function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
