<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\OAuthClientSecretHasher;

final readonly class RotateOAuthClientSecretAction
{
    public function __construct(
        private OAuthClientRepositoryInterface $clients,
        private OAuthClientSecretHasher $secrets,
        private AuditLogService $audit,
        private ClientIpResolver $ipResolver,
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

        if (!$client->isConfidential) {
            return OAuthClientSerializers::json($response, [
                'code' => 'oauth_client_is_public',
                'message' => 'Public OAuth clients do not have secrets to rotate.',
            ], 422);
        }

        $secret = $this->secrets->generateSecret();
        $rotated = $this->clients->transactional(function () use ($client, $context, $request, $secret): bool {
            $rotated = $this->clients->rotateSecret($client->clientIdentifier, $this->secrets->hash($secret));
            if (!$rotated) {
                return false;
            }

            $this->audit->record(
                action: 'sdk.oauth_client.rotate_secret',
                subjectType: 'oauth_client',
                subjectId: $client->id,
                actorUserId: $context->user?->id,
                organizationId: $client->organizationId,
                ipAddress: $this->ipResolver->resolve($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
                requestId: RequestIdContext::fromRequest($request),
                metadata: [
                    'client_id' => $client->clientIdentifier,
                    'grant_types' => $client->grantTypes,
                    'is_confidential' => $client->isConfidential,
                    'name' => $client->name,
                    'scopes' => $client->scopes,
                ],
            );

            return true;
        });
        if (!$rotated) {
            return OAuthClientSerializers::json($response, [
                'code' => 'oauth_client_not_found',
                'message' => 'OAuth client was not found in this organization scope.',
            ], 404);
        }

        return OAuthClientSerializers::json($response, [
            'client' => OAuthClientSerializers::client($client),
            'client_secret' => $secret,
        ], 200);
    }
}
