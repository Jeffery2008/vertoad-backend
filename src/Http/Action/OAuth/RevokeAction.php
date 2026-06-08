<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\OAuthTokenService;

final readonly class RevokeAction
{
    public function __construct(private OAuthTokenService $oauth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || trim((string) ($body['token'] ?? '')) === '') {
            return $this->json($response, 400, ['code' => 'invalid_request', 'message' => 'Token is required.']);
        }

        return $this->json($response, 200, $this->oauth->revoke((string) $body['token'], isset($body['token_type_hint']) ? (string) $body['token_type_hint'] : null, new DateTimeImmutable()));
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
