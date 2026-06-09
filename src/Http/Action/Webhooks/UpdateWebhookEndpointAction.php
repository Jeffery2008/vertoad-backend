<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;

final readonly class UpdateWebhookEndpointAction
{
    public function __construct(private WebhookEndpointRepositoryInterface $endpoints)
    {
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

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return WebhookEndpointSerializers::error($response, 422, 'invalid_request', 'JSON object payload is required.');
        }

        try {
            $updated = $this->endpoints->update($endpoint->withChanges(
                name: array_key_exists('name', $payload) ? CreateWebhookEndpointAction::stringField($payload, 'name') : null,
                endpointUrl: array_key_exists('endpoint_url', $payload)
                    ? CreateWebhookEndpointAction::stringField($payload, 'endpoint_url')
                    : null,
                status: array_key_exists('enabled', $payload)
                    ? (CreateWebhookEndpointAction::enabledField($payload) ? 'active' : 'paused')
                    : null,
                events: array_key_exists('events', $payload) ? CreateWebhookEndpointAction::eventsField($payload) : null,
                updatedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ));
        } catch (InvalidArgumentException $exception) {
            return WebhookEndpointSerializers::error($response, 422, 'invalid_webhook_endpoint', $exception->getMessage());
        }

        return WebhookEndpointSerializers::json($response, [
            'endpoint' => WebhookEndpointSerializers::endpoint($updated),
        ], 200);
    }
}
