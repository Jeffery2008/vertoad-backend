<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Domain\Webhooks\WebhookEventType;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
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

        $event = new WebhookEvent(
            eventId: 'evt_test_' . bin2hex(random_bytes(20)),
            eventType: WebhookEventType::WEBHOOK_TEST,
            organizationId: $endpoint->organizationId,
            data: [
                'endpoint_id' => $endpoint->endpointId,
                'message' => 'VertoAD webhook endpoint test delivery.',
            ],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            requestId: RequestIdContext::fromRequest($request),
        );
        $delivery = $this->deliveries->queueForEndpoint($endpoint, $event->eventType, $event->toArray());

        return WebhookEndpointSerializers::json($response, [
            'endpoint' => WebhookEndpointSerializers::endpoint($endpoint),
            'delivery' => WebhookEndpointSerializers::delivery($delivery),
        ], 202);
    }
}
