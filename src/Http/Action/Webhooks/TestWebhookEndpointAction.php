<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;

final readonly class TestWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointRepositoryInterface $endpoints,
        private WebhookDeliveryRepositoryInterface $deliveries,
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

        $endpoint = $this->endpoints->findForOrganization((string) ($args['endpoint_id'] ?? ''), (int) $context->organizationId);
        if ($endpoint === null) {
            return WebhookEndpointSerializers::error(
                $response,
                404,
                'webhook_endpoint_not_found',
                'Webhook endpoint was not found in this organization scope.',
            );
        }

        $delivery = $this->deliveries->queueForEndpoint($endpoint, 'webhook.test', [
            'type' => 'webhook.test',
            'endpoint_id' => $endpoint->endpointId,
            'organization_id' => $endpoint->organizationId,
            'message' => 'VertoAD webhook endpoint test delivery.',
        ]);

        return WebhookEndpointSerializers::json($response, [
            'endpoint' => WebhookEndpointSerializers::endpoint($endpoint),
            'delivery' => WebhookEndpointSerializers::delivery($delivery),
        ], 202);
    }
}
