<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;

final readonly class RotateWebhookEndpointSecretAction
{
    public function __construct(
        private WebhookEndpointRepositoryInterface $endpoints,
        private WebhookEndpointSecretCipherInterface $secrets,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return WebhookEndpointSerializers::json($response, $guard['payload'], $guard['status']);
        }

        $secret = $this->secrets->generateSigningSecret();
        $endpoint = $this->endpoints->rotateSecret(
            endpointId: (string) ($args['endpoint_id'] ?? ''),
            organizationId: (int) $context->organizationId,
            encryptedSigningSecret: $this->secrets->encrypt($secret),
            secretPreview: $this->secrets->preview($secret),
            secretRotatedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($endpoint === null) {
            return WebhookEndpointSerializers::error(
                $response,
                404,
                'webhook_endpoint_not_found',
                'Webhook endpoint was not found in this organization scope.',
            );
        }

        return WebhookEndpointSerializers::json($response, [
            'endpoint' => WebhookEndpointSerializers::endpoint($endpoint),
            'signing_secret' => $secret,
        ], 200);
    }
}
