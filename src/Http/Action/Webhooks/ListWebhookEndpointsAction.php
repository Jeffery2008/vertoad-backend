<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;

final readonly class ListWebhookEndpointsAction
{
    public function __construct(private WebhookEndpointRepositoryInterface $endpoints)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return WebhookEndpointSerializers::json($response, $guard['payload'], $guard['status']);
        }

        return WebhookEndpointSerializers::json($response, [
            'endpoints' => WebhookEndpointSerializers::endpoints(
                $this->endpoints->listForOrganization((int) $context->organizationId),
            ),
        ], 200);
    }
}
