<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Auth;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;

final readonly class LogoutAction
{
    public function __construct(
        private BearerTokenAuthenticator $authenticator,
        private FirstPartySessionRepositoryInterface $sessions,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        $tokenHash = $this->authenticator->tokenHashFromRequest($request);
        if ($tokenHash === null || !$this->sessions->revoke($tokenHash, new DateTimeImmutable())) {
            return $this->json($response, [
                'code' => 'session_not_found',
                'message' => 'The current session token could not be revoked.',
            ], 401);
        }

        return $this->json($response, ['revoked' => true], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
