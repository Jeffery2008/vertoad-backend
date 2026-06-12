<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Attribution;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Attribution\AttributionService;

final readonly class ServerConversionAction
{
    public function __construct(private AttributionService $attribution)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated()) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        if ($context->organizationId === null) {
            return $this->json($response, [
                'code' => 'organization_scope_required',
                'message' => 'A positive organization scope is required for this endpoint.',
            ], 400);
        }

        $body = $request->getParsedBody();

        try {
            $payload = is_array($body) ? $body : [];
            $result = $this->attribution->recordServerApiConversion(
                $payload,
                $context->organizationId,
                $context->oauthToken?->clientId,
                $context->user?->id,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, AttributionSerializers::conversion($result), 201);
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
