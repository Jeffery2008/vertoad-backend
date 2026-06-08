<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Service\OAuthClientSecretHasher;

final readonly class RotateOAuthClientSecretAction
{
    public function __construct(
        private OAuthClientRepositoryInterface $clients,
        private OAuthClientSecretHasher $secrets,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return OAuthClientSerializers::json($response, $guard['payload'], $guard['status']);
        }

        $clientId = is_string($args['client_id'] ?? null) ? trim($args['client_id']) : '';
        $client = $this->clients->findActiveByIdentifier($clientId);
        if ($client === null || $client->organizationId !== (int) $context->organizationId) {
            return OAuthClientSerializers::json($response, [
                'code' => 'oauth_client_not_found',
                'message' => 'OAuth client was not found in this organization scope.',
            ], 404);
        }

        $secret = $this->secrets->generateSecret();
        $this->clients->rotateSecret($client->clientIdentifier, $this->secrets->hash($secret));

        return OAuthClientSerializers::json($response, [
            'client' => OAuthClientSerializers::client($client),
            'client_secret' => $secret,
        ], 200);
    }
}
