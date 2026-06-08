<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\OAuthTokenService;

final readonly class AuthorizeAction
{
    public function __construct(private OAuthTokenService $oauth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if ($context->user === null) {
            return $this->json($response, 401, ['code' => 'authentication_required', 'message' => 'Authentication is required.']);
        }

        $query = $request->getQueryParams();
        try {
            $data = $this->oauth->authorize(
                $context->user->id,
                $context->organizationId,
                (string) ($query['client_id'] ?? ''),
                (string) ($query['redirect_uri'] ?? ''),
                $this->scopes((string) ($query['scope'] ?? '')),
                (string) ($query['code_challenge'] ?? ''),
                (string) ($query['code_challenge_method'] ?? ''),
                new DateTimeImmutable(),
            );
        } catch (\Throwable $exception) {
            return $this->json($response, 400, ['code' => 'invalid_oauth_request', 'message' => $exception->getMessage()]);
        }

        return $this->json($response, 200, $data);
    }

    /** @return list<string> */
    private function scopes(string $scope): array
    {
        return array_values(array_filter(array_unique(array_map('trim', preg_split('/\s+/', trim($scope)) ?: []))));
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
