<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Service\OAuthClientSecretHasher;

final readonly class CreateOAuthClientAction
{
    public function __construct(
        private OAuthClientRepositoryInterface $clients,
        private OAuthClientSecretHasher $secrets,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return OAuthClientSerializers::json($response, $guard['payload'], $guard['status']);
        }

        $organizationId = (int) $context->organizationId;
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return $this->error($response, 422, 'invalid_request', 'JSON object payload is required.');
        }

        try {
            $secret = $this->secrets->generateSecret();
            $client = $this->clients->store(new OAuthClient(
                id: null,
                organizationId: $organizationId,
                ownerUserId: $context->user?->id,
                clientIdentifier: $this->generateClientIdentifier(),
                name: self::stringField($payload, 'name'),
                secretHash: $this->secrets->hash($secret),
                redirectUris: self::stringListField($payload, 'redirect_uris'),
                grantTypes: self::stringListField($payload, 'grant_types'),
                scopes: self::stringListField($payload, 'scopes', required: false),
                isConfidential: (bool) ($payload['is_confidential'] ?? true),
                revokedAt: null,
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, 422, 'invalid_oauth_client', $exception->getMessage());
        }

        return OAuthClientSerializers::json($response, [
            'client' => OAuthClientSerializers::client($client),
            'client_secret' => $secret,
        ], 201);
    }

    private function error(ResponseInterface $response, int $status, string $code, string $message): ResponseInterface
    {
        return OAuthClientSerializers::json($response, ['code' => $code, 'message' => $message], $status);
    }

    /** @param array<string, mixed> $payload */
    private static function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function stringListField(array $payload, string $key, bool $required = true): array
    {
        $value = $payload[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('%s must be an array of strings.', $key));
        }

        $strings = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
        if ($required && $strings === []) { throw new InvalidArgumentException(sprintf('%s requires at least one string.', $key)); }

        return $strings;
    }

    private function generateClientIdentifier(): string
    {
        return 'vocs_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
