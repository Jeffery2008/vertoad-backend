<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;

final readonly class ListWebhookDeliveriesAction
{
    public function __construct(private WebhookDeliveryRepositoryInterface $deliveries)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return WebhookEndpointSerializers::json($response, $guard['payload'], $guard['status']);
        }

        $query = $request->getQueryParams();
        $limit = $this->limit($query['limit'] ?? null);
        if ($limit === null) {
            return WebhookEndpointSerializers::error($response, 422, 'invalid_request', 'limit must be a positive integer no greater than 100.');
        }

        return WebhookEndpointSerializers::json($response, [
            'deliveries' => WebhookEndpointSerializers::deliveries($this->deliveries->listForOrganization(
                organizationId: (int) $context->organizationId,
                endpointId: is_string($query['endpoint_id'] ?? null) ? trim((string) $query['endpoint_id']) : null,
                status: is_string($query['status'] ?? null) ? trim((string) $query['status']) : null,
                limit: $limit,
            )),
        ], 200);
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 50;
        }

        if (is_string($value) && ctype_digit($value)) {
            $limit = (int) $value;
            return $limit > 0 && $limit <= 100 ? $limit : null;
        }

        if (is_int($value)) {
            return $value > 0 && $value <= 100 ? $value : null;
        }

        return null;
    }
}
