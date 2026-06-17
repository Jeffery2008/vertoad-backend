<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Creative;

use Psr\Http\Message\ResponseInterface;

final class CreativeJson
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

    public static function error(ResponseInterface $response, int $statusCode, string $code, string $message): ResponseInterface
    {
        return self::write($response, [
            'code' => $code,
            'message' => $message,
        ], $statusCode);
    }
}
