<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Auth\OAuthClient;

final class OAuthClientSerializers
{
    /** @return array<string, mixed> */
    public static function client(OAuthClient $client): array
    {
        return [
            'id' => $client->id,
            'organization_id' => $client->organizationId,
            'owner_user_id' => $client->ownerUserId,
            'client_id' => $client->clientIdentifier,
            'name' => $client->name,
            'redirect_uris' => $client->redirectUris,
            'grant_types' => $client->grantTypes,
            'scopes' => $client->scopes,
            'is_confidential' => $client->isConfidential,
            'revoked' => $client->isRevoked(),
        ];
    }

    /**
     * @param list<OAuthClient> $clients
     * @return list<array<string, mixed>>
     */
    public static function clients(array $clients): array
    {
        return array_map(static fn (OAuthClient $client): array => self::client($client), $clients);
    }

    /** @param array<string, mixed> $payload */
    public static function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
